#include "data_logging.h"
#include "config.h"
#include "state.h"
#include "settings.h"
#include "boot_ui.h"

#include <SD.h>
#include <time.h>
#include <vector>
#include <algorithm>
#include <numeric> // ✅ CORRECTION : Ajouté pour std::accumulate

// Déclaration des variables externes définies dans main.cpp
extern volatile float g_rawMaisonW;
extern portMUX_TYPE g_shellyMux;
extern SemaphoreHandle_t g_sdMutex; // Notre "gardien" pour la carte SD

// Variables statiques internes au module
static char logBuffer[LOG_BUFFER_MAX_SIZE];
static size_t logBufferPosition = 0;
static int lastSaveDay = -1;
static unsigned long lastTalonSampleTime = 0;

namespace DataLogging {

// Initialise les dossiers nécessaires sur la carte SD
void init() {
    // On demande le "jeton" (mutex) avant de toucher à la carte SD
    if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(2000)) == pdTRUE) {
        if (!SD.exists("/talon")) SD.mkdir("/talon");
        if (!SD.exists("/data")) SD.mkdir("/data");
        if (!SD.exists(INVOICES_BASE_DIR)) SD.mkdir(INVOICES_BASE_DIR);
        if (!SD.exists(DAILY_JSON_DIR)) SD.mkdir(DAILY_JSON_DIR);
        if (!SD.exists(ARCHIVE_DIR)) SD.mkdir(ARCHIVE_DIR);
        // On rend le jeton une fois qu'on a fini
        xSemaphoreGive(g_sdMutex);
    } else {
        Serial.println("[ERROR] Failed to acquire SD mutex in DataLogging::init()");
    }
    logBuffer[0] = '\0';
}

// Écrit un message dans le buffer de log
void writeLog(LogLevel level, const String& message) {
    const char* levelStr[] = {"INFO", "WARN", "ERROR", "DEBUG"};
    struct tm timeinfo;
    char timeStr[20];
    
    if (getLocalTime(&timeinfo, 10)) { // Timeout non bloquant
        sprintf(timeStr, "%02d:%02d:%02d", timeinfo.tm_hour, timeinfo.tm_min, timeinfo.tm_sec);
    } else {
        strcpy(timeStr, "00:00:00");
    }
    
    String logEntry = String(timeStr) + " [" + levelStr[(int)level] + "] " + message + "\n";
    Serial.print(logEntry); // On affiche toujours sur le port série pour le debug
    
    // Le buffer de log lui-même n'a pas besoin de mutex car c'est une variable globale simple
    size_t len = logEntry.length();
    if (logBufferPosition + len < LOG_BUFFER_MAX_SIZE) {
        strcat(logBuffer, logEntry.c_str());
        logBufferPosition += len;
    } else {
        flushLogBufferToSD(); // Le buffer est plein, on le vide sur la carte SD
        if (len < LOG_BUFFER_MAX_SIZE) {
            strcpy(logBuffer, logEntry.c_str());
            logBufferPosition = len;
        }
    }
}

size_t getLogBufferLength() {
    return logBufferPosition;
}

// Vide le buffer de log sur la carte SD de manière sécurisée
void flushLogBufferToSD() {
    if (logBufferPosition == 0) return;
    
    // On demande le jeton avant d'écrire sur la carte SD
    if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(1000)) == pdTRUE) {
        File logFile = SD.open(LOG_FILE, FILE_APPEND);
        
        if (logFile) {
            logFile.print(logBuffer);
            logFile.close();
            
            // Le buffer a bien été écrit, on le vide
            logBuffer[0] = '\0';
            logBufferPosition = 0;
        } else {
            Serial.println("[ERROR] Failed to open log file for writing. Buffer preserved.");
            // Si l'ouverture échoue, on ne vide pas le buffer pour ne pas perdre les logs
        }
        
        xSemaphoreGive(g_sdMutex); // On rend le jeton
    } else {
        Serial.println("[ERROR] Failed to acquire SD mutex for log flush.");
    }
}

// Nettoie les anciens fichiers de log
void cleanupLogs() {
    flushLogBufferToSD(); // On s'assure que tout est écrit avant de vérifier la taille
    
    if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(2000)) == pdTRUE) {
        File f = SD.open(LOG_FILE, FILE_READ);
        if (f) {
            if (f.size() > MAX_LOG_SIZE_BYTES) {
                f.close();
                SD.remove(LOG_FILE);
                writeLog(LogLevel::LOG_INFO, "Log file cleaned (size exceeded)");
            } else {
                f.close();
            }
        }
        xSemaphoreGive(g_sdMutex);
    }
}

// Calcule la valeur du "talon" de consommation électrique
void calculateTalonValue() {
    writeLog(LogLevel::LOG_INFO, "Starting talon calculation...");
    const char* sample_file = "/talon/power_samples.log";
    std::vector<float> samples;
    bool success = false;
    
    if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(3000)) == pdTRUE) {
        if (!SD.exists(sample_file)) {
            writeLog(LogLevel::LOG_WARN, "Talon sample file not found. Skipping.");
            powerData.talon_power = "Pas de donnees";
            xSemaphoreGive(g_sdMutex);
            return;
        }
        
        File f = SD.open(sample_file, FILE_READ);
        if (f) {
            while (f.available()) {
                samples.push_back(f.readStringUntil('\n').toFloat());
                yield(); // Évite le blocage sur de gros fichiers
            }
            f.close();
            SD.remove(sample_file);
            success = true;
        } else {
            writeLog(LogLevel::LOG_ERROR, "Failed to open talon sample file.");
            powerData.talon_power = "Erreur lecture";
        }
        xSemaphoreGive(g_sdMutex);
    } else {
        writeLog(LogLevel::LOG_ERROR, "Failed to acquire SD mutex for talon calculation.");
        return;
    }
    
    if (!success) return;

    if (samples.size() < 10) {
        writeLog(LogLevel::LOG_WARN, "Not enough samples for talon calc: " + String(samples.size()));
        powerData.talon_power = "Donnees insuffisantes";
        return;
    }
    
    std::sort(samples.begin(), samples.end());
    int index = (int)(samples.size() * 0.20); // 20ème percentile
    float talon = samples[index];
    powerData.talon_power = String(talon, 0) + " W";
    writeLog(LogLevel::LOG_INFO, "Talon calculated successfully: " + powerData.talon_power);
    
    // Sauvegarde de l'historique
    if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(2000)) == pdTRUE) {
        File history = SD.open("/talon/power_history.csv", FILE_APPEND);
        if (history) {
            struct tm timeinfo;
            getLocalTime(&timeinfo);
            char line[50];
            sprintf(line, "%04d-%02d-%02d,%.0f\n",
                timeinfo.tm_year + 1900, timeinfo.tm_mon + 1, timeinfo.tm_mday, talon);
            history.print(line);
            history.close();
        }
        xSemaphoreGive(g_sdMutex);
    }
}

// Gère la logique de mesure du talon (fenêtre de temps, etc.)
void handleTalonLogic() {
    struct tm timeinfo;
    if (!getLocalTime(&timeinfo)) return;
    
    // Démarrage de la fenêtre de mesure
    if (timeinfo.tm_hour == SETTINGS.talon_start_hour && timeinfo.tm_min == 0 && !sysState.isTalonWindow) {
        if (timeinfo.tm_yday != sysState.lastTalonCalculationDay) {
            sysState.isTalonWindow = true;
            sysState.talonCalculationPending = true;
            lastTalonSampleTime = 0;
            
            if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(1000)) == pdTRUE) {
                if (SD.exists("/talon/power_samples.log")) SD.remove("/talon/power_samples.log");
                xSemaphoreGive(g_sdMutex);
            }
            writeLog(LogLevel::LOG_INFO, "Entering talon measurement window.");
        }
    }
    
    // Fin de la fenêtre de mesure
    if (timeinfo.tm_hour == SETTINGS.talon_end_hour && timeinfo.tm_min >= SETTINGS.talon_end_minute && sysState.isTalonWindow) {
        sysState.isTalonWindow = false;
        writeLog(LogLevel::LOG_INFO, "Exiting talon measurement window. Calculation is pending.");
    }
    
    // Pendant la fenêtre, on échantillonne la puissance
    if (sysState.isTalonWindow && millis() - lastTalonSampleTime >= 60000) {
        lastTalonSampleTime = millis();
        portENTER_CRITICAL(&g_shellyMux);
        float currentPower = g_rawMaisonW;
        portEXIT_CRITICAL(&g_shellyMux);
        
        if (!isnan(currentPower)) {
            if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(1000)) == pdTRUE) {
                File f = SD.open("/talon/power_samples.log", FILE_APPEND);
                if (f) { f.println(currentPower); f.close(); }
                xSemaphoreGive(g_sdMutex);
            }
        }
    }
    
    // Après la fenêtre, on lance le calcul
    if (!sysState.isTalonWindow && sysState.talonCalculationPending) {
        calculateTalonValue();
        sysState.talonCalculationPending = false;
        sysState.lastTalonCalculationDay = timeinfo.tm_yday;
    }
}

// Sauvegarde la consommation d'eau journalière
void saveDailyWaterConsumption() {
    struct tm timeinfo;
    if (!getLocalTime(&timeinfo)) return;
    
    if (timeinfo.tm_hour == 23 && timeinfo.tm_min == 59) {
        if (timeinfo.tm_yday != lastSaveDay) {
            lastSaveDay = timeinfo.tm_yday;
            float consumption = sensorData.eau_litres.toFloat();
            if (consumption <= 0) return;
            
            char filename[32];
            sprintf(filename, "/data/water_%04d_%02d.csv", timeinfo.tm_year + 1900, timeinfo.tm_mon + 1);
            
            if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(2000)) == pdTRUE) {
                File dataFile = SD.open(filename, FILE_APPEND);
                if (dataFile) {
                    char dataLine[32];
                    sprintf(dataLine, "%04d-%02d-%02d,%.0f\n",
                        timeinfo.tm_year + 1900, timeinfo.tm_mon + 1, timeinfo.tm_mday, consumption);
                    dataFile.print(dataLine);
                    dataFile.close();
                    writeLog(LogLevel::LOG_INFO, "Saved daily water consumption: " + String(consumption) + "L");
                } else {
                    writeLog(LogLevel::LOG_ERROR, "Failed to open " + String(filename));
                }
                xSemaphoreGive(g_sdMutex);
            }
        }
    } else {
        if (timeinfo.tm_yday != lastSaveDay) lastSaveDay = -1;
    }
}

// Calcule les statistiques de consommation d'eau
void calculateWaterStats() {
    writeLog(LogLevel::LOG_INFO, "Calculating water statistics...");
    g_waterStats = WaterStats();
    
    struct tm timeinfo;
    if (!getLocalTime(&timeinfo)) return;
    
    std::vector<float> dailyReadings;
    int currentYear = timeinfo.tm_year + 1900;
    
    if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(5000)) == pdTRUE) {
        for (int m = 1; m <= 12; ++m) {
            char filename[32];
            sprintf(filename, "/data/water_%04d_%02d.csv", currentYear, m);
            if (SD.exists(filename)) {
                File file = SD.open(filename);
                if (file) {
                    while (file.available()) {
                        String line = file.readStringUntil('\n');
                        int commaIndex = line.indexOf(',');
                        if (commaIndex != -1) dailyReadings.push_back(line.substring(commaIndex + 1).toFloat());
                        yield();
                    }
                    file.close();
                }
            }
            yield();
        }
        xSemaphoreGive(g_sdMutex);
    } else {
        writeLog(LogLevel::LOG_ERROR, "Failed to acquire SD mutex for water stats.");
        return;
    }
    
    if (!dailyReadings.empty()) {
        std::reverse(dailyReadings.begin(), dailyReadings.end());
        g_waterStats.yesterday = String(dailyReadings[0], 0) + " L";
        
        if (dailyReadings.size() >= 7) {
            float sum7d = std::accumulate(dailyReadings.begin(), dailyReadings.begin() + 7, 0.0f);
            g_waterStats.avg7d = String(sum7d / 7.0f, 0) + " L";
        }
        
        if (dailyReadings.size() >= 30) {
            float sum30d = std::accumulate(dailyReadings.begin(), dailyReadings.begin() + 30, 0.0f);
            g_waterStats.avg30d = String(sum30d / 30.0f, 0) + " L";
        }
    }
    
    g_waterStats.dataLoaded = true;
    writeLog(LogLevel::LOG_INFO, "Water statistics calculation complete.");
}

// Charge les stats d'eau si elles ne le sont pas déjà
void loadWaterData() {
    if (!g_waterStats.dataLoaded) {
        calculateWaterStats();
    }
}

} // namespace DataLogging
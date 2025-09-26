#include "data_logging.h"
#include "config.h"
#include "state.h"
#include "settings.h"
#include "boot_ui.h" // Ajout pour utiliser directement le module de boot
#include <SD.h>
#include <vector>
#include <numeric>
#include <algorithm>

static char logBuffer[LOG_BUFFER_MAX_SIZE];
static size_t logBufferPosition = 0;

static unsigned long lastLogFlush = 0;
static int lastSaveDay = -1;
static unsigned long lastTalonSampleTime = 0;

extern volatile float g_rawMaisonW;
extern portMUX_TYPE g_shellyMux;

namespace DataLogging {

void init() {
    if (!SD.exists("/talon")) SD.mkdir("/talon");
    if (!SD.exists("/data"))  SD.mkdir("/data");
    if (!SD.exists(INVOICES_BASE_DIR)) SD.mkdir(INVOICES_BASE_DIR);
    if (!SD.exists(DAILY_JSON_DIR))    SD.mkdir(DAILY_JSON_DIR);
    if (!SD.exists(ARCHIVE_DIR))       SD.mkdir(ARCHIVE_DIR);

    logBuffer[0] = '\0';
}

void writeLog(LogLevel level, const String& message) {
    const char* levelStr[] = {"INFO", "WARN", "ERROR", "DEBUG"};
    struct tm timeinfo;
    char timeStr[20];
    if (getLocalTime(&timeinfo)) {
        sprintf(timeStr, "%02d:%02d:%02d", timeinfo.tm_hour, timeinfo.tm_min, timeinfo.tm_sec);
    } else {
        strcpy(timeStr, "00:00:00");
    }
    String logEntry = String(timeStr) + " [" + levelStr[(int)level] + "] " + message + "\n";
    Serial.print(logEntry);

    size_t len = logEntry.length();
    if (logBufferPosition + len < LOG_BUFFER_MAX_SIZE) {
        strcat(logBuffer, logEntry.c_str());
        logBufferPosition += len;
    } else {
        flushLogBufferToSD();
        if (len < LOG_BUFFER_MAX_SIZE) {
            strcpy(logBuffer, logEntry.c_str());
            logBufferPosition = len;
        }
    }
}

size_t getLogBufferLength() {
    return logBufferPosition;
}

void flushLogBufferToSD() {
    if (logBufferPosition == 0) return;
    File logFile = SD.open(LOG_FILE, FILE_APPEND);
    if (logFile) {
        logFile.print(logBuffer);
        logFile.close();
    }
    logBuffer[0] = '\0';
    logBufferPosition = 0;
    lastLogFlush = millis();
}

void cleanupLogs() {
    flushLogBufferToSD();
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
}

void calculateTalonValue() {
    writeLog(LogLevel::LOG_INFO, "Starting talon calculation...");
    const char* sample_file = "/talon/power_samples.log";

    if (!SD.exists(sample_file)) {
        writeLog(LogLevel::LOG_WARN, "Talon sample file not found. Skipping.");
        powerData.talon_power = "Pas de donnees";
        return;
    }

    File f = SD.open(sample_file);
    if (!f) {
        writeLog(LogLevel::LOG_ERROR, "Failed to open talon sample file.");
        powerData.talon_power = "Erreur lecture";
        return;
    }

    std::vector<float> samples;
    while (f.available()) {
        samples.push_back(f.readStringUntil('\n').toFloat());
    }
    f.close();
    SD.remove(sample_file);

    if (samples.size() < 10) {
        writeLog(LogLevel::LOG_WARN, "Not enough samples for talon calc: " + String(samples.size()));
        powerData.talon_power = "Donnees insuffisantes";
        return;
    }

    std::sort(samples.begin(), samples.end());
    int index = (int)(samples.size() * 0.20);
    float talon = samples[index];

    powerData.talon_power = String(talon, 0) + " W";
    writeLog(LogLevel::LOG_INFO, "Talon calculated successfully: " + powerData.talon_power);

    File history = SD.open("/talon/power_history.csv", FILE_APPEND);
    if (history) {
        struct tm timeinfo;
        getLocalTime(&timeinfo);
        char line[50];
        sprintf(line, "%04d-%02d-%02d,%.0f\n", timeinfo.tm_year + 1900, timeinfo.tm_mon + 1, timeinfo.tm_mday, talon);
        history.print(line);
        history.close();
    }
}

void handleTalonLogic() {
    struct tm timeinfo;
    if (!getLocalTime(&timeinfo)) return;

    if (timeinfo.tm_hour == SETTINGS.talon_start_hour && timeinfo.tm_min == 0 && !sysState.isTalonWindow) {
         if (timeinfo.tm_yday != sysState.lastTalonCalculationDay) {
            sysState.isTalonWindow = true;
            sysState.talonCalculationPending = true;
            lastTalonSampleTime = 0;
            if(SD.exists("/talon/power_samples.log")) SD.remove("/talon/power_samples.log");
            writeLog(LogLevel::LOG_INFO, "Entering talon measurement window.");
        }
    }

    if (timeinfo.tm_hour == SETTINGS.talon_end_hour && timeinfo.tm_min >= SETTINGS.talon_end_minute && sysState.isTalonWindow) {
        sysState.isTalonWindow = false;
        writeLog(LogLevel::LOG_INFO, "Exiting talon measurement window. Calculation is pending.");
    }
    
    if (sysState.isTalonWindow && millis() - lastTalonSampleTime >= 60000) {
        lastTalonSampleTime = millis();
        portENTER_CRITICAL(&g_shellyMux);
        float currentPower = g_rawMaisonW;
        portEXIT_CRITICAL(&g_shellyMux);

        if (!isnan(currentPower)) {
            File f = SD.open("/talon/power_samples.log", FILE_APPEND);
            if (f) {
                f.println(currentPower);
                f.close();
            }
        }
    }

    if (!sysState.isTalonWindow && sysState.talonCalculationPending) {
        calculateTalonValue();
        sysState.talonCalculationPending = false;
        sysState.lastTalonCalculationDay = timeinfo.tm_yday;
    }
}

void saveDailyWaterConsumption() {
    struct tm timeinfo;
    if (!getLocalTime(&timeinfo)) return;

    if (timeinfo.tm_hour == 23 && timeinfo.tm_min == 59) {
        if (timeinfo.tm_yday != lastSaveDay) {
            lastSaveDay = timeinfo.tm_yday;
            float consumption = sensorData.eau_litres.toFloat();
            if (consumption <= 0) {
                 writeLog(LogLevel::LOG_WARN, "Water consumption is 0 or invalid, not saving.");
                 return;
            }
            char filename[32];
            sprintf(filename, "/data/water_%04d_%02d.csv", timeinfo.tm_year + 1900, timeinfo.tm_mon + 1);
            File dataFile = SD.open(filename, FILE_APPEND);
            if (!dataFile) {
                writeLog(LogLevel::LOG_ERROR, "Failed to open " + String(filename) + " for writing");
                return;
            }
            char dataLine[32];
            sprintf(dataLine, "%04d-%02d-%02d,%.0f\n", timeinfo.tm_year + 1900, timeinfo.tm_mon + 1, timeinfo.tm_mday, consumption);
            dataFile.print(dataLine);
            dataFile.close();
            writeLog(LogLevel::LOG_INFO, "Saved daily water consumption: " + String(consumption) + "L to " + String(filename));
        }
    } else {
        if (timeinfo.tm_yday != lastSaveDay) {
            lastSaveDay = -1;
        }
    }
}

void calculateWaterStats() {
    writeLog(LogLevel::LOG_INFO, "Calculating water statistics...");
    g_waterStats = WaterStats(); 
    struct tm timeinfo;
    if (!getLocalTime(&timeinfo)) {
        writeLog(LogLevel::LOG_ERROR, "Cannot get time for water stats calculation.");
        return;
    }

    std::vector<float> dailyReadings;
    float monthlyTotals[12] = {0.0f};
    int currentYear = timeinfo.tm_year + 1900;
    int currentMonth = timeinfo.tm_mon + 1;

    for (int m = 1; m <= 12; ++m) {
        char filename[32];
        sprintf(filename, "/data/water_%04d_%02d.csv", currentYear, m);
        if (SD.exists(filename)) {
            File file = SD.open(filename);
            if (file) {
                while (file.available()) {
                    String line = file.readStringUntil('\n');
                    int commaIndex = line.indexOf(',');
                    if (commaIndex != -1) {
                        float value = line.substring(commaIndex + 1).toFloat();
                        dailyReadings.push_back(value);
                        if (m <= currentMonth) {
                           monthlyTotals[m-1] += value;
                        }
                    }
                }
                file.close();
            }
        }
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
        float sumYear = std::accumulate(dailyReadings.begin(), dailyReadings.end(), 0.0f);
        if (!dailyReadings.empty()) g_waterStats.avgYear = String(sumYear / dailyReadings.size(), 0) + " L";
    }

    float maxConsumption = 0.0;
    int bestMonthIdx = -1;
    for (int i = 0; i < 12; ++i) {
        if (monthlyTotals[i] > maxConsumption) {
            maxConsumption = monthlyTotals[i];
            bestMonthIdx = i;
        }
    }
    
    if (bestMonthIdx != -1) {
        const char* monthNames[] = {"Jan", "Fev", "Mar", "Avr", "Mai", "Juin", "Juil", "Aou", "Sep", "Oct", "Nov", "Dec"};
        g_waterStats.bestMonth = String(monthNames[bestMonthIdx]) + " (" + String(maxConsumption, 0) + "L)";
    }
    g_waterStats.dataLoaded = true;
    writeLog(LogLevel::LOG_INFO, "Water statistics calculation complete.");
}


static TaskHandle_t s_waterTask = nullptr;

void startWaterStatsAsync() {
    if (s_waterTask) return;
    xTaskCreatePinnedToCore([](void*){
        calculateWaterStats();
        s_waterTask = nullptr;
        vTaskDelete(nullptr);
    }, "waterStats", 4096, nullptr, 1, &s_waterTask, 0);
}

void loadWaterData() {
    if (!g_waterStats.dataLoaded) {
        startWaterStatsAsync();
    }
}

} // namespace DataLogging
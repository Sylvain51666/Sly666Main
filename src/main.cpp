#include <WiFi.h>
#include <time.h>
#include <SD.h>
#include <SPIFFS.h>
#include <vector>
#include <deque>

#include "config.h"
#include "state.h"
#include "ui.h"
#include "network.h"
#include "shelly.h"
#include "weather.h"
#include "settings.h"
#include "web_server.h"
#include "data_logging.h"
#include "utils.h"
#include "invoices_store.h"
#include "invoices_screen.h"
#include "sonometer_screen.h"
#include "boot_ui.h"

constexpr const char* APP_VERSION = "v29.0";

// ============================== VARIABLES GLOBALES ==============================
SystemState sysState;
DisplayState dispState;
PowerData powerData;
SensorData sensorData;
WeatherData g_weather;
SolarTimes g_solarTimes;
PowerStats g_powerStats;
WaterStats g_waterStats;
InvoiceParams g_invoiceParams;
InvoiceData g_invoiceData;

std::vector<uint8_t> g_wind_icon_png;
std::vector<uint8_t> g_humidity_icon_png;
std::vector<uint8_t> g_flame_icon_png;

volatile float g_rawMaisonW = NAN, g_rawPVW = NAN;
volatile bool g_hasNewShelly = false;
portMUX_TYPE g_shellyMux = portMUX_INITIALIZER_UNLOCKED;

TaskHandle_t g_shellyTaskHandle = nullptr;
TaskHandle_t g_backgroundTaskHandle = nullptr;

// CORRECTION #2: Mutex pour protéger les accès SD
SemaphoreHandle_t g_sdMutex = nullptr;

static bool timeIsSet = false;
static bool invoicesIsInitialized = false;
static bool boot_complete = false;

// ============================== PROTOTYPES ==============================
void setState(SystemStateType newState);
void checkSystemHealth();
void handleNtpConnection();
bool shouldRunDailyJobs(const struct tm& timeinfo);
void loadLastJobDay();
void saveLastJobDay(const struct tm& timeinfo);
void drawMainDashboard();
void backgroundTask(void* arg);

// ============================== SETUP ==============================
void setup() {
    Serial.begin(115200);
    auto cfg = M5.config();
    M5.begin(cfg);

    const int TOTAL_BOOT_STEPS = 14;
    int current_step = 0;

    BootUI::begin();
    BootUI::drawVersion(APP_VERSION);

    // SPIFFS
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Systeme Fichiers (SPIFFS)");
    if (!SPIFFS.begin(true)) {
        M5.Display.fillScreen(TFT_RED);
        M5.Display.drawString("SPIFFS Mount Failed", 160, 120);
        while(true) delay(1000);
    }

    // SD Card
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Systeme Fichiers (SD)");
    if (!SD.begin(4)) {
        M5.Display.fillScreen(TFT_RED);
        M5.Display.drawString("SD Card Mount Failed", 160, 120);
        while (true) delay(1000);
    }

    // CORRECTION #2: Création du mutex SD
    g_sdMutex = xSemaphoreCreateMutex();
    if (g_sdMutex == nullptr) {
        M5.Display.fillScreen(TFT_RED);
        M5.Display.drawString("SD Mutex Creation Failed", 160, 120);
        while (true) delay(1000);
    }

    // Configuration
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Chargement Configuration");
    if (!Settings.begin(SD, "/config.json")) {
        BootUI::setProgress((current_step * 100 / TOTAL_BOOT_STEPS), "config.json cree");
        delay(1000);
    }

    Settings.onChange([](const AppSettings& now, const AppSettings& old){
        if (now.brightness_day != old.brightness_day || now.brightness_night != old.brightness_night) {
            UI::updateBrightness();
        }
    });

    // Logs
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Initialisation Logs");
    DataLogging::init();
    DataLogging::writeLog(LogLevel::LOG_INFO, String("=== SYSTEM BOOT === ") + APP_VERSION);
    sysState.bootTime = millis();
    randomSeed(esp_random());

    // WiFi
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Connexion WiFi...");
    WiFi.onEvent([](WiFiEvent_t event, WiFiEventInfo_t info){
        if (event == ARDUINO_EVENT_WIFI_STA_GOT_IP) {
            Serial.print(F("[WIFI] IP: "));
            Serial.println(WiFi.localIP());
        }
    });
    Network::init();

    // Web Server
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Serveur Web...");
    bool webOk = WebServerInstance.begin("m5core2");
    if (webOk) {
        Serial.println(F("[WEB] Serveur demarre."));
        BootUI::setStep(current_step, TOTAL_BOOT_STEPS, "[WEB] Serveur demarre.");
    } else {
        Serial.println(F("[WEB] Echec demarrage serveur. Continuing..."));
        BootUI::setStep(current_step, TOTAL_BOOT_STEPS, "[WARNING] Web Server failed, continuing...");
    }

    // NTP
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Synchronisation Heure (NTP)");
    struct tm timeinfo;
    if (WiFi.isConnected()) {
        configTime(GMT_OFFSET_SEC, DAYLIGHT_OFFSET_SEC, NTP_SERVER);
        if (getLocalTime(&timeinfo, 5000)) timeIsSet = true;
    }

    // MQTT
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Connexion MQTT...");
    Network::connectMqtt();

    if(timeIsSet) {
        BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Calcul Solaire");
        Utils::calculateSolarTimes();
        dispState.isNightMode = UI::shouldBeNightMode();
    } else {
        current_step++;
    }

    // Invoices
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Initialisation Factures");
    InvoicesStore::init();
    loadLastJobDay();
    DataLogging::writeLog(LogLevel::LOG_INFO, "Post-boot: Processing invoices data...");
    InvoicesStore::checkContinuityAndRefetch();
    invoicesIsInitialized = true;
    DataLogging::writeLog(LogLevel::LOG_INFO, "Invoices data processed.");

    // Weather
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Recuperation Meteo");
    bool weatherOk = Weather::fetch();
    if (!weatherOk) {
        BootUI::setStep(current_step, TOTAL_BOOT_STEPS, "[WARNING] Weather failed, using fallback.");
        Serial.println("[ERROR] Weather fetch failed, continuing...");
    }

    // UI
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Chargement Interface");
    UI::init();

    // Background tasks
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Lancement Taches de Fond");
    Shelly::startTask();
    
    xTaskCreatePinnedToCore(
        backgroundTask,
        "BackgroundOps",
        8192,
        nullptr,
        1,
        &g_backgroundTaskHandle,
        1  // Core 1 (core 0 pour Shelly)
    );

    sysState.lastDataReceived = millis();
    BootUI::setProgress(100, "Pret !");
    delay(1500);
    BootUI::setActive(false);
    boot_complete = true;
    drawMainDashboard();
}

// ============================== FONCTIONS UTILITAIRES ==============================
void drawMainDashboard() {
    dispState.needsRedraw = true;
    UI::applyModeChangeNow();
}

void handleNtpConnection() {
    static unsigned long lastNtpTry = 0;
    if (!timeIsSet && WiFi.isConnected() && (millis() - lastNtpTry > 60000)) {
        DataLogging::writeLog(LogLevel::LOG_INFO, "Attempting NTP time sync...");
        configTime(GMT_OFFSET_SEC, DAYLIGHT_OFFSET_SEC, NTP_SERVER);
        struct tm timeinfo;
        if (getLocalTime(&timeinfo, 5000)) {
            timeIsSet = true;
            DataLogging::writeLog(LogLevel::LOG_INFO, "NTP time synchronized successfully.");
            Utils::calculateSolarTimes();
            dispState.isNightMode = UI::shouldBeNightMode();
        } else {
            DataLogging::writeLog(LogLevel::LOG_WARN, "NTP time sync failed. Will retry.");
        }
        lastNtpTry = millis();
    }
}

void loadLastJobDay() {
    if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(1000)) == pdTRUE) {
        File f = SD.open(LAST_FETCH_DAY_FILE);
        if (f && f.size() > 0) {
            sysState.lastInvoiceJobDayOfYear = f.parseInt();
        } else {
            sysState.lastInvoiceJobDayOfYear = -1;
        }
        if (f) f.close();
        xSemaphoreGive(g_sdMutex);
    }
}

void saveLastJobDay(const struct tm& timeinfo) {
    if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(1000)) == pdTRUE) {
        File f = SD.open(LAST_FETCH_DAY_FILE, FILE_WRITE);
        if (f) {
            sysState.lastInvoiceJobDayOfYear = timeinfo.tm_yday;
            f.print(sysState.lastInvoiceJobDayOfYear);
            f.close();
        }
        xSemaphoreGive(g_sdMutex);
    }
}

bool shouldRunDailyJobs(const struct tm& timeinfo) {
    bool isTime = (timeinfo.tm_hour > SETTINGS.daily_fetch_hour) ||
                  (timeinfo.tm_hour == SETTINGS.daily_fetch_hour && timeinfo.tm_min >= SETTINGS.daily_fetch_minute);
    bool notDoneToday = (timeinfo.tm_yday != sysState.lastInvoiceJobDayOfYear);
    return isTime && notDoneToday;
}

void setState(SystemStateType newState) {
    if (sysState.type == newState) return;
    sysState.type = newState;
    if (sysState.type != SystemStateType::STATE_OK) {
        if (sysState.errorStateStartTime == 0) sysState.errorStateStartTime = millis();
    } else {
        sysState.errorStateStartTime = 0;
        dispState.needsRedraw = true;
    }
}

void checkSystemHealth() {
    if (WiFi.isConnected()) {
        if (Network::mqttClient.connected()) {
            if (millis() - sysState.lastDataReceived > DATA_TIMEOUT_MS) {
                setState(SystemStateType::STATE_NO_DATA);
            } else {
                setState(SystemStateType::STATE_OK);
            }
        } else {
            setState(SystemStateType::STATE_NO_MQTT);
        }
    } else {
        setState(SystemStateType::STATE_NO_WIFI);
    }

    if (sysState.type != SystemStateType::STATE_OK &&
        sysState.errorStateStartTime > 0 &&
        millis() - sysState.errorStateStartTime > HARD_REBOOT_TIMEOUT_MS) {
        DataLogging::writeLog(LogLevel::LOG_ERROR, "In error state for 15 minutes. Rebooting...");
        DataLogging::flushLogBufferToSD();
        delay(1000);
        ESP.restart();
    }
}

// ============================== TÂCHE DE FOND ==============================
void backgroundTask(void* arg) {
    unsigned long lastLogFlush = 0;
    unsigned long lastLogCleanup = 0;
    unsigned long lastWeatherFetch = 0;

    for (;;) {
        unsigned long now = millis();

        // Flush logs de manière asynchrone
        if (now - lastLogFlush > LOG_FLUSH_INTERVAL_MS ||
            DataLogging::getLogBufferLength() > (LOG_BUFFER_MAX_SIZE * 0.8)) {
            DataLogging::flushLogBufferToSD();
            lastLogFlush = now;
        }

        // Cleanup logs
        if (now - lastLogCleanup > LOG_CLEANUP_INTERVAL_MS) {
            DataLogging::cleanupLogs();
            lastLogCleanup = now;
        }

        // Weather fetch asynchrone
        if (now - lastWeatherFetch > SETTINGS.weather_refresh_interval_ms) {
            Weather::fetch();
            lastWeatherFetch = now;
        }

        // Sauvegarde eau quotidienne
        if (invoicesIsInitialized) {
            DataLogging::saveDailyWaterConsumption();
        }

        vTaskDelay(pdMS_TO_TICKS(1000));
    }
}

// ============================== LOOP PRINCIPALE ==============================
void loop() {
    M5.update();

    if (!boot_complete) {
        BootUI::loop();
        return;
    }

    unsigned long now = millis();
    static Screen lastScreen = dispState.currentScreen;
    static unsigned long lastBrightnessCheck = 0;

    if (dispState.currentScreen == Screen::SCREEN_SONOMETER) {
        Sonometer::update();
        return;
    }

    UI::handleInput();

    if (dispState.currentScreen != lastScreen) {
        if (dispState.currentScreen == Screen::SCREEN_DASHBOARD ||
            dispState.currentScreen == Screen::SCREEN_INVOICES) {
            dispState.needsRedraw = true;
        }
        lastScreen = dispState.currentScreen;
    }

    handleNtpConnection();

    if (invoicesIsInitialized) {
        DataLogging::handleTalonLogic();
        struct tm timeinfo;
        if (getLocalTime(&timeinfo)) {
            if (shouldRunDailyJobs(timeinfo)) {
                DataLogging::writeLog(LogLevel::LOG_INFO, "Daily jobs trigger. Last run yday=" + String(sysState.lastInvoiceJobDayOfYear));
                InvoicesStore::triggerDailyUpdate();
                saveLastJobDay(timeinfo);
            }
        }
    }

    if (dispState.currentScreen == Screen::SCREEN_INVOICES) {
        if (dispState.needsRedraw) {
            InvoicesScreen::show();
            dispState.needsRedraw = false;
        }
        InvoicesScreen::loop();
        return;
    }

    checkSystemHealth();
    Network::loop();

    switch (sysState.type) {
        case SystemStateType::STATE_OK:
            if (dispState.needsRedraw) {
                UI::applyModeChangeNow();
                UI::updateAlertIcons();
                dispState.needsRedraw = false;
            }

            if (timeIsSet && now - lastBrightnessCheck > BRIGHTNESS_CHECK_INTERVAL_MS) {
                UI::updateBrightness();
                if (dispState.currentMode == DisplayMode::MODE_AUTO &&
                    UI::shouldBeNightMode() != dispState.isNightMode) {
                    dispState.needsRedraw = true;
                }
                lastBrightnessCheck = now;
            }

            if (g_hasNewShelly) {
                float maisonW, pvW;
                portENTER_CRITICAL(&g_shellyMux);
                maisonW = g_rawMaisonW;
                pvW = g_rawPVW;
                g_hasNewShelly = false;
                portEXIT_CRITICAL(&g_shellyMux);

                if (isnan(maisonW) || isnan(pvW)) {
                    powerData.maison_watts = "...";
                    powerData.pv_watts = "...";
                    powerData.grid_watts = "...";
                } else {
                    if (pvW < 10) pvW = 0;
                    powerData.current_pv_watts_float = pvW;
                    powerData.pv_watts = Utils::formatShelly3Chars(pvW);

                    if (maisonW >= 0) {
                        powerData.maison_watts = Utils::formatShelly3Chars(maisonW);
                        powerData.current_grid_watts_float = 0;
                        powerData.grid_watts = "0";
                    } else {
                        float inj = fabs(maisonW);
                        powerData.maison_watts = "0";
                        powerData.current_grid_watts_float = inj;
                        powerData.grid_watts = Utils::formatShelly3Chars(inj);
                    }
                    powerData.isGridPositive = powerData.current_grid_watts_float > 10.0f;
                }
                sysState.lastDataReceived = now;
            }

            UI::updateAnimations();
            UI::updateAllDisplays();
            UI::updateAlertIcons();
            break;

        case SystemStateType::STATE_NO_WIFI:
        case SystemStateType::STATE_NO_MQTT:
        case SystemStateType::STATE_NO_DATA:
            UI::showErrorScreen();
            break;
    }

    yield();
    delay(5);
}

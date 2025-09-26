#include <M5Unified.h>
#include <WiFi.h>
#include <SD.h>
#include <SPIFFS.h>
#include <vector>
#include <esp_random.h>

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

constexpr const char* APP_VERSION = "v28.0"; // Version mise à jour

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

static bool timeIsSet = false;
static bool invoicesIsInitialized = false;
static bool boot_complete = false;

void setState(SystemStateType newState);
void checkSystemHealth();
void handleNtpConnection();
bool shouldRunDailyJobs(const struct tm& timeinfo);
void loadLastJobDay();
void saveLastJobDay(const struct tm& timeinfo);
void drawMainDashboard();

void setup() {
  Serial.begin(115200);
  auto cfg = M5.config();
  M5.begin(cfg);

  const int TOTAL_BOOT_STEPS = 14;
  int current_step = 0;

  BootUI::begin();
  BootUI::drawVersion(APP_VERSION);

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Systeme Fichiers (SPIFFS)");
  if (!SPIFFS.begin(true)) {
    M5.Display.fillScreen(TFT_RED); M5.Display.drawString("SPIFFS Mount Failed", 160, 120); while(true) delay(1000);
  }

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Systeme Fichiers (SD)");
  if (!SD.begin(4)) {
    M5.Display.fillScreen(TFT_RED); M5.Display.drawString("SD Card Mount Failed", 160, 120); while (true) delay(1000);
  }
  
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

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Initialisation Logs");
  DataLogging::init();
  DataLogging::writeLog(LogLevel::LOG_INFO, String("=== SYSTEM BOOT ===") + APP_VERSION);
  sysState.bootTime = millis();
  randomSeed(esp_random());

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Connexion WiFi...");
  WiFi.onEvent([](WiFiEvent_t event, WiFiEventInfo_t info){
    if (event == ARDUINO_EVENT_WIFI_STA_GOT_IP) {
      Serial.print(F("[WIFI] IP: ")); Serial.println(WiFi.localIP());
    }
  });
  Network::init();

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Serveur Web...");
  bool webOk = WebServerInstance.begin("m5core2");
  if (webOk) {
    Serial.println(F("[WEB] Serveur demarre."));
    BootUI::setStep(current_step, TOTAL_BOOT_STEPS, "[WEB] Serveur demarre.");
  } else {
    Serial.println(F("[WEB] Echec demarrage serveur. Continuing..."));
    BootUI::setStep(current_step, TOTAL_BOOT_STEPS, "[WARNING] Web Server failed, continuing...");
  }

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Synchronisation Heure (NTP)");
  struct tm timeinfo;
  if (WiFi.isConnected()) {
    configTime(GMT_OFFSET_SEC, DAYLIGHT_OFFSET_SEC, NTP_SERVER);
    if (getLocalTime(&timeinfo, 5000)) timeIsSet = true;
  }

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Connexion MQTT...");
  Network::connectMqtt();

  if(timeIsSet) {
    BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Calcul Solaire");
    Utils::calculateSolarTimes();
    dispState.isNightMode = UI::shouldBeNightMode();
  } else { current_step++; }

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Initialisation Factures");
  InvoicesStore::init();
  loadLastJobDay();
  DataLogging::writeLog(LogLevel::LOG_INFO, "Post-boot: Processing invoices data...");
  InvoicesStore::checkContinuityAndRefetch();
  invoicesIsInitialized = true;
  DataLogging::writeLog(LogLevel::LOG_INFO, "Invoices data processed.");

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Recuperation Meteo");
  bool weatherOk = Weather::fetch();
  if (!weatherOk) {
    BootUI::setStep(current_step, TOTAL_BOOT_STEPS, "[WARNING] Weather failed, using fallback.");
    Serial.println("[ERROR] Weather fetch failed, continuing...");
  }

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Chargement Interface");
  UI::init();

  BootUI::setStep(++current_step, TOTAL_BOOT_STEPS, "Lancement Taches de Fond");
  Shelly::startTask();
  sysState.lastDataReceived = millis();
  
  BootUI::setProgress(100, "Pret !");
  delay(1500);
  BootUI::setActive(false);

  boot_complete = true;
  drawMainDashboard();
}

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
  File f = SD.open(LAST_FETCH_DAY_FILE);
  if (f && f.size() > 0) {
    sysState.lastInvoiceJobDayOfYear = f.parseInt();
  } else {
    sysState.lastInvoiceJobDayOfYear = -1;
  }
  if (f) f.close();
}

void saveLastJobDay(const struct tm& timeinfo) {
  File f = SD.open(LAST_FETCH_DAY_FILE, FILE_WRITE);
  if (f) {
    sysState.lastInvoiceJobDayOfYear = timeinfo.tm_yday;
    f.print(sysState.lastInvoiceJobDayOfYear);
    f.close();
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

  if (sysState.type != SystemStateType::STATE_OK && sysState.errorStateStartTime > 0 && millis() - sysState.errorStateStartTime > HARD_REBOOT_TIMEOUT_MS) {
    DataLogging::writeLog(LogLevel::LOG_ERROR, "In error state for 15 minutes. Rebooting...");
    DataLogging::flushLogBufferToSD();
    delay(1000);
    ESP.restart();
  }
}

void loop() {
  M5.update();

  if (!boot_complete) {
    BootUI::loop();
    return;
  }

  unsigned long now = millis();
  static Screen lastScreen = dispState.currentScreen;
  static unsigned long lastLogFlush = 0;
  static unsigned long lastLogCleanup = 0;
  static unsigned long lastBrightnessCheck = 0;
  static unsigned long lastWeatherFetch = 0;

  if (dispState.currentScreen == Screen::SCREEN_SONOMETER) {
    Sonometer::update();
    return;
  }

  UI::handleInput();

  if (dispState.currentScreen != lastScreen) {
    if (dispState.currentScreen == Screen::SCREEN_DASHBOARD || dispState.currentScreen == Screen::SCREEN_INVOICES) {
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
        UI::updateAlertIcons(); // Mettre à jour les icônes après un redessin complet
        dispState.needsRedraw = false;
      }

      if (now - lastWeatherFetch > SETTINGS.weather_refresh_interval_ms) { 
        Weather::fetch(); 
        lastWeatherFetch = now; 
      }
      if (now - lastLogFlush > LOG_FLUSH_INTERVAL_MS || DataLogging::getLogBufferLength() > LOG_BUFFER_MAX_SIZE) { DataLogging::flushLogBufferToSD(); lastLogFlush = now; }
      if (now - lastLogCleanup > LOG_CLEANUP_INTERVAL_MS) { DataLogging::cleanupLogs(); lastLogCleanup = now; }

      if (timeIsSet && now - lastBrightnessCheck > BRIGHTNESS_CHECK_INTERVAL_MS) {
        UI::updateBrightness();
        if (dispState.currentMode == DisplayMode::MODE_AUTO && UI::shouldBeNightMode() != dispState.isNightMode) {
          dispState.needsRedraw = true;
        }
        lastBrightnessCheck = now;
      }

      if (invoicesIsInitialized) DataLogging::saveDailyWaterConsumption();

      if (g_hasNewShelly) {
        float maisonW, pvW;
        portENTER_CRITICAL(&g_shellyMux);
        maisonW = g_rawMaisonW; pvW = g_rawPVW; g_hasNewShelly = false;
        portEXIT_CRITICAL(&g_shellyMux);

        if (isnan(maisonW) || isnan(pvW)) {
          powerData.maison_watts = "..."; powerData.pv_watts = "..."; powerData.grid_watts = "...";
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
      UI::updateAlertIcons(); // Appel constant pour vérifier l'état des icônes
      break;

    case SystemStateType::STATE_NO_WIFI:
    case SystemStateType::STATE_NO_MQTT:
    case SystemStateType::STATE_NO_DATA:
      UI::showErrorScreen();
      break;
  }

  delay(10);
}
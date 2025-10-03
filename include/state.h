#pragma once

#include "M5Unified.h"
#include <vector>
#include <deque>
#include <freertos/FreeRTOS.h>
#include <freertos/semphr.h> // Pour SemaphoreHandle_t

// ============================== ENUMS ==============================
enum class SystemStateType { STATE_OK, STATE_NO_WIFI, STATE_NO_MQTT, STATE_NO_DATA };
enum class DisplayMode { MODE_AUTO = 0, MODE_FORCE_DAY = 1, MODE_FORCE_NIGHT = 2 };
enum class LogLevel { LOG_INFO, LOG_WARN, LOG_ERROR, LOG_DEBUG };
enum class Screen { SCREEN_DASHBOARD, SCREEN_INVOICES, SCREEN_SONOMETER };

// ============================== STRUCTURES DE DONNÉES ==============================

struct SystemState {
    SystemStateType type = SystemStateType::STATE_OK;
    unsigned long bootTime = 0;
    unsigned long errorStateStartTime = 0;
    unsigned long lastDataReceived = 0;
    int wifiReconnectAttempts = 0;
    int mqttReconnectAttempts = 0;
    int lastInvoiceJobDayOfYear = -1;
    int lastTalonCalculationDay = -1;
    bool isTalonWindow = false;
    bool talonCalculationPending = false;
};

struct DisplayState {
    DisplayMode currentMode = DisplayMode::MODE_AUTO;
    Screen currentScreen = Screen::SCREEN_DASHBOARD;
    bool needsRedraw = true;
    bool isNightMode = false;
    bool isWindIconOnScreen = false;
    bool isHumidityIconOnScreen = false;
    bool isFlameIconOnScreen = false;
};

struct PowerData {
    String maison_watts = "...";
    String pv_watts = "...";
    String grid_watts = "...";
    String autoconsommation = "...";
    String prod_totale = "...";
    String talon_power = "Calcul en attente";
    float current_pv_watts_float = 0.0f;
    float current_grid_watts_float = 0.0f;
    bool isGridPositive = false;
};

struct SensorData {
    String heure = "--:--";
    String piscine_temp = "...";
    String pac_temp = "";
    String eau_litres = "...";
    String pompe_start = "--:--";
    String pompe_end = "--:--";
    String talon_water = "En attente...";
    float temp_onduleur1 = -999.0f;
    float temp_onduleur2 = -999.0f;
    float studioHumidity = -1.0f;
    bool isPumpRunning = false;
    float pac_value_float = NAN;
};

struct WeatherData {
    float currentWindKmh = NAN;
    float maxWindTodayKmh = NAN;
    float maxGustTodayKmh = NAN;
    unsigned long lastUpdateMs = 0;
    int lastHttpCode = 0;
    bool valid = false;
};

struct SolarTimes {
    int sunriseHour = 7, sunriseMinute = 0;
    int sunsetHour = 19, sunsetMinute = 0;
    int dayOfYear = 0;
    bool isValid = false;
};

struct PowerStats {
    float minPower = 99999, maxPower = 0, avgPower = 0;
    int sampleCount = 0;
};

struct WaterStats {
    bool dataLoaded = false;
    String yesterday = "--- L";
    String avg7d = "--- L";
    String avg30d = "--- L";
};

struct TouchState {
    bool isTouched = false;
    unsigned long firstTapTime = 0;
    int tapCount = 0;
    bool doubleTapDetected = false;
};

struct InvoiceParams {
    double priceHc;
    double priceHp;
    double monthlySubscription;
    int billingStartDay;
};

struct InvoiceData {
    double currentBillingTotal = 0.0;
    double totalHcKwh = 0.0;
    double totalHpKwh = 0.0;
    time_t lastUpdateTimestamp = 0;
    String lastApiError = "N/A";
    int lastApiHttpCode = 0;
};

// ============================== DÉCLARATIONS EXTERNES ==============================

extern SystemState sysState;
extern DisplayState dispState;
extern PowerData powerData;
extern SensorData sensorData;
extern WeatherData g_weather;
extern SolarTimes g_solarTimes;
extern PowerStats g_powerStats;
extern WaterStats g_waterStats;
extern InvoiceParams g_invoiceParams;
extern InvoiceData g_invoiceData;

extern std::vector<uint8_t> g_wind_icon_png;
extern std::vector<uint8_t> g_humidity_icon_png;
extern std::vector<uint8_t> g_flame_icon_png;

// Déclaration de notre gardien (Mutex) pour la carte SD
extern SemaphoreHandle_t g_sdMutex;
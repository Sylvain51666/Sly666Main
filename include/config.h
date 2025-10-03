#pragma once
#include "secrets.h"
#include <M5Unified.h>

// --- Assurez-vous que vos secrets sont bien définis ---
#ifndef WIFI_SSID
#error "WiFi credentials are not defined! Please copy secrets.h.template to secrets.h and fill it out."
#endif

// --- Constantes système fixes ---
constexpr const char* NTP_SERVER = "pool.ntp.org";
constexpr long  GMT_OFFSET_SEC = 3600;
constexpr int   DAYLIGHT_OFFSET_SEC = 3600;
constexpr uint32_t SHELLY_POLL_MS = 1000;
constexpr unsigned long DATA_TIMEOUT_MS = 300000;       // 5 minutes
constexpr unsigned long HARD_REBOOT_TIMEOUT_MS = 900000; // 15 minutes
constexpr unsigned long MQTT_RECONNECT_INTERVAL_MS = 5000;
constexpr unsigned long WIFI_RECONNECT_INTERVAL_MS = 10000;
constexpr unsigned long BRIGHTNESS_CHECK_INTERVAL_MS = 10000;
constexpr unsigned long LOG_CLEANUP_INTERVAL_MS = 3600000; // 1 heure
constexpr unsigned long LOG_FLUSH_INTERVAL_MS = 30000;     // 30 secondes
constexpr unsigned long FORCE_REDRAW_INTERVAL_MS = 5000;
constexpr unsigned long MODE_SWITCH_COOLDOWN_MS = 1000;
constexpr unsigned long DOUBLE_TAP_TIMEOUT_MS = 500;

// --- Chemins des fichiers assets sur la carte SD ---
constexpr const char* HUMIDITY_ICON_PATH = "/goutte.png";
constexpr const char* WEATHER_ICON_PATH = "/vent_fort.png";
constexpr const char* FLAME_ICON_PATH = "/flamme.png";

// --- Configuration des logs ---
constexpr const char* LOG_FILE = "/system.log";
constexpr unsigned long MAX_LOG_SIZE_BYTES = 50 * 1024 * 1024; // 50 Mo
constexpr size_t LOG_BUFFER_MAX_SIZE = 4096;

// --- Configuration du module de facturation ---
constexpr const char* INVOICES_BASE_DIR = "/invoices";
constexpr const char* DAILY_JSON_DIR = "/invoices/daily_json";
constexpr const char* ARCHIVE_DIR = "/invoices/archive";
constexpr const char* DEBUG_DATA_FILE = "/invoices/debug.json";
constexpr const char* LAST_FETCH_DAY_FILE = "/invoices/last_fetch_day.txt";

// Valeurs par défaut si le MQTT ne les fournit pas
constexpr double DEFAULT_PRICE_HP = 0.1733;
constexpr double DEFAULT_PRICE_HC = 0.1382;
constexpr double DEFAULT_ABO_MOIS = 21.69;
constexpr int DEFAULT_BILLING_DAY = 24;


// --- Disposition de l'UI et polices ---
#define FONT_MAISON     7
#define FONT_EAU        4
#define FONT_GRID       4
#define FONT_PISCINE    4
#define FONT_PAC        2
#define FONT_PV         2
#define FONT_HEURE      2

#define HEURE_DISP_X 290
#define HEURE_DISP_Y 10
#define HEURE_DISP_W 60
#define HEURE_DISP_H 20
#define MAISON_DISP_X 160
#define MAISON_DISP_Y 30
#define MAISON_DISP_W 150
#define MAISON_DISP_H 60
#define PV_DISP_X 59
#define PV_DISP_Y 48
#define PV_DISP_W 62
#define PV_DISP_H 23
#define GRID_DISP_X 180
#define GRID_DISP_Y 128
#define GRID_DISP_W 100
#define GRID_DISP_H 40
#define EAU_DISP_X 270
#define EAU_DISP_Y 85
#define EAU_DISP_W 100
#define EAU_DISP_H 40  // <-- LA LIGNE MANQUANTE EST ICI
#define PISCINE_DISP_W 120
#define PISCINE_DISP_H 35
#define PISCINE_DISP_Y 222
#define PISCINE_DISP_MARGIN_LEFT 0
#define PAC_FAN_X 234
#define PAC_FAN_Y 198
#define PAC_FAN_W 38
#define PAC_FAN_H 37
#define PAC_DISP_X 253
#define PAC_DISP_Y 186
#define PAC_DISP_W 32
#define PAC_DISP_H 20
#define WAVE_W 138
#define WAVE_H 27
#define WAVE_X 82
#define WAVE_Y 213
#define GRID_ARROW_W 36
#define GRID_ARROW_H 36
#define GRID_ARROW_X 123
#define GRID_ARROW_Y 113
#define PV_TRAPEZE_P0_X 66
#define PV_TRAPEZE_P0_Y 69
#define PV_TRAPEZE_P1_X 141
#define PV_TRAPEZE_P1_Y 69
#define PV_TRAPEZE_P2_X 118
#define PV_TRAPEZE_P2_Y 100
#define PV_TRAPEZE_P3_X 40
#define PV_TRAPEZE_P3_Y 100

const int PV_GFX_X = min(PV_TRAPEZE_P0_X, PV_TRAPEZE_P3_X);
const int PV_GFX_Y = min(PV_TRAPEZE_P0_Y, PV_TRAPEZE_P1_Y);
const int PV_GFX_W = max(PV_TRAPEZE_P1_X, PV_TRAPEZE_P2_X) - PV_GFX_X;
const int PV_GFX_H = max(PV_TRAPEZE_P2_Y, PV_TRAPEZE_P3_Y) - PV_GFX_Y;

constexpr int HOUR_TOUCH_X = 220, HOUR_TOUCH_Y = 0, HOUR_TOUCH_W = 100, HOUR_TOUCH_H = 40;
constexpr unsigned long WAVE_PERIOD_MS = 250;
constexpr unsigned long GRID_ARROW_PERIOD_MS = 180;
constexpr unsigned long FAN_FRAME_PERIOD_MS = 200;
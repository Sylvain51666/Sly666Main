#include "ui.h"
#include "config.h"
#include "state.h"
#include "settings.h"
#include "utils.h"
#include "data_logging.h"
#include "network.h"
#include <M5Unified.h>
#include <WiFi.h>
#include <SD.h>
#include <vector>
#include <ArduinoJson.h>
#include <cmath>
#include "pac_fan_assets.h"
#include "invoices_debug.h"
#include "sonometer_screen.h"
#include "boot_ui.h"

// --- Sprites & Buffers ---
static LGFX_Sprite spHeure(&M5.Display), spMaison(&M5.Display), spPV(&M5.Display), spGrid(&M5.Display);
static LGFX_Sprite spEau(&M5.Display), spPiscine(&M5.Display), spPAC(&M5.Display), spSolar(&M5.Display);
static LGFX_Sprite spFan(&M5.Display), spWave(&M5.Display), spGridArrow(&M5.Display);
static uint16_t* bg_buffer_heure = nullptr, *bg_buffer_maison = nullptr, *bg_buffer_pv = nullptr;
static uint16_t* bg_buffer_grid = nullptr, *bg_buffer_eau = nullptr, *bg_buffer_piscine = nullptr;
static uint16_t* bg_buffer_pac = nullptr, *bg_buffer_wave = nullptr, *bg_buffer_solar = nullptr;
static uint16_t* bg_buffer_fan = nullptr, *bg_buffer_grid_arrow = nullptr, *bg_buffer_toast = nullptr;

// --- AJOUT : Variables statiques pour la gestion des icônes ---
static bool last_wind_state = false;
static bool last_humidity_state = false;
static bool last_flame_state = false;
static uint16_t* bg_buffer_icons = nullptr;
static const int ICON_AREA_X = 5, ICON_AREA_Y = 5;
static const int ICON_WIDTH = 32, ICON_SPACING = 2;
static const int ICON_AREA_W = ICON_WIDTH + 4;
static const int ICON_AREA_H = (ICON_WIDTH + ICON_SPACING) * 3;

// --- Last known values for redraw optimization ---
static String last_val_heure = "", last_val_maison_watts = "", last_val_piscine_temp = "", last_val_pac_temp = "";
static String last_val_pv_watts = "", last_val_grid_watts = "", last_val_eau_litres = "";
static float last_current_pv_watts_float = -1.0f;
static unsigned long lastForceRedraw = 0;

// --- Animation State ---
static unsigned long lastFanFrameUpdate = 0, lastWaveUpdate = 0, lastGridArrowUpdate = 0;
static int fanFrame = 0, gridArrowFrame = 0;
static bool isPacRunning = false, pacStateChanged = true;
static bool pumpStateChanged = true, gridArrowStateChanged = true;
static std::vector<uint8_t> wave1_png, wave2_png, wave3_png;
static std::vector<uint8_t> grid_png[6];

// --- Interaction State ---
static TouchState touchState;
static unsigned long lastModeSwitch = 0;

// --- Toast State ---
static bool toastVisible = false;
static unsigned long toastUntil = 0;

// --- Internal Declarations ---
static void readAllBackgroundBuffers();
static bool readFileToBuffer(const String& path, std::vector<uint8_t>& out);
static bool drawBackgroundFromSD(bool nightMode);
static String findBgPath(bool nightMode);
static void drawSpriteText(LGFX_Sprite& sp, int w, int h, const String& text, uint8_t font, uint16_t color, uint16_t* bg, textdatum_t datum, int x_offset = 0);
static void updateSolarFill();
static void updatePacAnimation();
static void updatePoolWaves();
static void updateGridArrow();
static void showModeToast(const char* text);
static void hideToast();
static void maybeUpdateToast();
static uint16_t getPowerGradientColor(float watts);


namespace UI {

void init() {
  readFileToBuffer("/wave1.png", wave1_png);
  readFileToBuffer("/wave2.png", wave2_png);
  readFileToBuffer("/wave3.png", wave3_png);
  for (int i = 0; i < 6; ++i) { readFileToBuffer("/grid" + String(i + 1) + ".png", grid_png[i]); }
  readFileToBuffer(WEATHER_ICON_PATH, g_wind_icon_png);
  readFileToBuffer(HUMIDITY_ICON_PATH, g_humidity_icon_png);
  readFileToBuffer("/flamme.png", g_flame_icon_png);
  
  bg_buffer_heure = (uint16_t*)malloc(HEURE_DISP_W * HEURE_DISP_H * sizeof(uint16_t));
  bg_buffer_maison = (uint16_t*)malloc(MAISON_DISP_W * MAISON_DISP_H * sizeof(uint16_t));
  bg_buffer_pv = (uint16_t*)malloc(PV_DISP_W * PV_DISP_H * sizeof(uint16_t));
  bg_buffer_grid = (uint16_t*)malloc(GRID_DISP_W * GRID_DISP_H * sizeof(uint16_t));
  bg_buffer_eau = (uint16_t*)malloc(EAU_DISP_W * EAU_DISP_H * sizeof(uint16_t));
  bg_buffer_piscine = (uint16_t*)malloc(PISCINE_DISP_W * PISCINE_DISP_H * sizeof(uint16_t));
  bg_buffer_pac = (uint16_t*)malloc(PAC_DISP_W * PAC_DISP_H * sizeof(uint16_t));
  bg_buffer_solar = (uint16_t*)malloc(PV_GFX_W * PV_GFX_H * sizeof(uint16_t));
  bg_buffer_fan = (uint16_t*)malloc(PAC_FAN_W * PAC_FAN_H * sizeof(uint16_t));
  bg_buffer_wave = (uint16_t*)malloc(WAVE_W * WAVE_H * sizeof(uint16_t));
  bg_buffer_grid_arrow = (uint16_t*)malloc(GRID_ARROW_W * GRID_ARROW_H * sizeof(uint16_t));
  
  bg_buffer_icons = (uint16_t*)malloc(ICON_AREA_W * ICON_AREA_H * sizeof(uint16_t));
  
  spHeure.createSprite(HEURE_DISP_W, HEURE_DISP_H); spMaison.createSprite(MAISON_DISP_W, MAISON_DISP_H);
  spPV.createSprite(PV_DISP_W, PV_DISP_H); spGrid.createSprite(GRID_DISP_W, GRID_DISP_H);
  spEau.createSprite(EAU_DISP_W, EAU_DISP_H); spPiscine.createSprite(PISCINE_DISP_W, PISCINE_DISP_H);
  spPAC.createSprite(PAC_DISP_W, PAC_DISP_H); spSolar.createSprite(PV_GFX_W, PV_GFX_H);
  spFan.createSprite(PAC_FAN_W, PAC_FAN_H); spWave.createSprite(WAVE_W, WAVE_H);
  spGridArrow.createSprite(GRID_ARROW_W, GRID_ARROW_H);
  
  spHeure.setTextPadding(HEURE_DISP_W);
  spMaison.setTextPadding(MAISON_DISP_W);
  spPV.setTextPadding(PV_DISP_W);
  spGrid.setTextPadding(GRID_DISP_W);
  spEau.setTextPadding(EAU_DISP_W);
  spPiscine.setTextPadding(PISCINE_DISP_W);
  spPAC.setTextPadding(PAC_DISP_W);
}

void applyModeChangeNow() {
  updateBrightness();
  dispState.isNightMode = shouldBeNightMode();
  if (!drawBackgroundFromSD(dispState.isNightMode)) {
    DataLogging::writeLog(LogLevel::LOG_ERROR, "BG load fail, using fallback");
    M5.Display.fillScreen(TFT_BLACK);
  }
  
  readAllBackgroundBuffers();
  lastForceRedraw = 0;
  updateAllDisplays();
  updateSolarFill();
  switch (dispState.currentMode) {
    case DisplayMode::MODE_FORCE_NIGHT: showModeToast("NUIT"); break;
    case DisplayMode::MODE_FORCE_DAY: showModeToast("JOUR"); break;
    case DisplayMode::MODE_AUTO: showModeToast("AUTO"); break;
  }
}

void updateAllDisplays() {
  bool force = (millis() - lastForceRedraw > FORCE_REDRAW_INTERVAL_MS);
  if (force) lastForceRedraw = millis();
  
  if (force || sensorData.heure != last_val_heure) {
    drawSpriteText(spHeure, HEURE_DISP_W, HEURE_DISP_H, sensorData.heure, FONT_HEURE, TFT_BLACK, bg_buffer_heure, textdatum_t::middle_center);
    spHeure.pushSprite(HEURE_DISP_X - HEURE_DISP_W / 2, HEURE_DISP_Y - HEURE_DISP_H / 2);
    last_val_heure = sensorData.heure;
  }
  
  if (force || powerData.maison_watts != last_val_maison_watts) {
    float w = powerData.maison_watts.toFloat() * (powerData.maison_watts.indexOf('.') > 0 ? 1000 : 1);
    uint16_t color = getPowerGradientColor(w);
    spMaison.fillSprite(TFT_MAGENTA);
    spMaison.setTextFont(FONT_MAISON);
    spMaison.setTextColor(color);
    spMaison.setTextDatum(textdatum_t::middle_center);
    spMaison.drawString(powerData.maison_watts, MAISON_DISP_W / 2, MAISON_DISP_H / 2);
    M5.Display.pushImage(MAISON_DISP_X - MAISON_DISP_W / 2, MAISON_DISP_Y - MAISON_DISP_H / 2, MAISON_DISP_W, MAISON_DISP_H, bg_buffer_maison);
    spMaison.pushSprite(MAISON_DISP_X - MAISON_DISP_W / 2, MAISON_DISP_Y - MAISON_DISP_H / 2, TFT_MAGENTA);
    last_val_maison_watts = powerData.maison_watts;
  }
  
  if (force || powerData.pv_watts != last_val_pv_watts) {
    // Note: This uses the special font set in a previous fix, not FONT_PV from config.h
    spPV.pushImage(0, 0, PV_DISP_W, PV_DISP_H, bg_buffer_pv);
    spPV.setFont(&fonts::FreeSans12pt7b);
    spPV.setTextColor(TFT_BLACK);
    spPV.setTextDatum(textdatum_t::middle_center);
    spPV.drawString(powerData.pv_watts, PV_DISP_W / 2, PV_DISP_H / 2);
    spPV.pushSprite(PV_DISP_X - PV_DISP_W / 2, PV_DISP_Y - PV_DISP_H / 2);
    last_val_pv_watts = powerData.pv_watts;
  }
  
  if (force || powerData.grid_watts != last_val_grid_watts) {
    drawSpriteText(spGrid, GRID_DISP_W, GRID_DISP_H, powerData.grid_watts, FONT_GRID, TFT_BLACK, bg_buffer_grid, textdatum_t::middle_center);
    spGrid.pushSprite(GRID_DISP_X - GRID_DISP_W / 2, GRID_DISP_Y - GRID_DISP_H / 2);
    last_val_grid_watts = powerData.grid_watts;
  }
  
  if (force || sensorData.eau_litres != last_val_eau_litres) {
    drawSpriteText(spEau, EAU_DISP_W, EAU_DISP_H, sensorData.eau_litres, FONT_EAU, TFT_BLACK, bg_buffer_eau, textdatum_t::middle_center);
    spEau.pushSprite(EAU_DISP_X - EAU_DISP_W / 2, EAU_DISP_Y - EAU_DISP_H / 2);
    last_val_eau_litres = sensorData.eau_litres;
  }
  
  if (force || sensorData.piscine_temp != last_val_piscine_temp) {
    drawSpriteText(spPiscine, PISCINE_DISP_W, PISCINE_DISP_H, sensorData.piscine_temp, FONT_PISCINE, TFT_BLACK, bg_buffer_piscine, textdatum_t::middle_left, 0);
    spPiscine.pushSprite(PISCINE_DISP_X - PISCINE_DISP_W / 2, PISCINE_DISP_Y - PISCINE_DISP_H / 2);
    last_val_piscine_temp = sensorData.piscine_temp;
  }
  
  if (force || sensorData.pac_temp != last_val_pac_temp) {
    drawSpriteText(spPAC, PAC_DISP_W, PAC_DISP_H, sensorData.pac_temp, FONT_PAC, TFT_BLACK, bg_buffer_pac, textdatum_t::middle_center);
    spPAC.pushSprite(PAC_DISP_X - PAC_DISP_W / 2, PAC_DISP_Y - PAC_DISP_H / 2);
    last_val_pac_temp = sensorData.pac_temp;
  }
  
  M5.Display.fillCircle(10, 10, 4, millis() - sysState.lastDataReceived > 30000 ? TFT_RED : TFT_GREEN);
}

void updateAnimations() {
  updatePacAnimation();
  updatePoolWaves();
  updateGridArrow();
  updateSolarFill();
  maybeUpdateToast();
}

void handleInput() {
  if (dispState.currentScreen == Screen::SCREEN_DASHBOARD && M5.BtnA.wasPressed()) {
    Sonometer::enter();
    dispState.currentScreen = Screen::SCREEN_SONOMETER;
    return;
  }
  if (dispState.currentScreen == Screen::SCREEN_DASHBOARD && M5.BtnC.wasPressed()) {
    dispState.currentScreen = Screen::SCREEN_INVOICES;
    dispState.needsRedraw = true;
    return;
  }

  bool currentlyTouched = M5.Touch.getCount() > 0;
  unsigned long now = millis();

  if (currentlyTouched && !touchState.isTouched) {
    touchState.isTouched = true;
    auto touch = M5.Touch.getDetail(0);
    bool inHourZone = (touch.x >= HOUR_TOUCH_X && touch.x <= HOUR_TOUCH_X + HOUR_TOUCH_W && touch.y >= HOUR_TOUCH_Y && touch.y <= HOUR_TOUCH_Y + HOUR_TOUCH_H);
    
    if (inHourZone) {
      if (touchState.tapCount == 0 || (now - touchState.firstTapTime > DOUBLE_TAP_TIMEOUT_MS)) {
        touchState.firstTapTime = now;
        touchState.tapCount = 1;
      } else if (touchState.tapCount == 1 && (now - touchState.firstTapTime <= DOUBLE_TAP_TIMEOUT_MS)) {
        touchState.doubleTapDetected = true;
        touchState.tapCount = 0;
      }
      touchState.lastTapTime = now;
    }
  } else if (!currentlyTouched && touchState.isTouched) {
    touchState.isTouched = false;
  }

  if (touchState.tapCount > 0 && (now - touchState.firstTapTime > DOUBLE_TAP_TIMEOUT_MS)) {
    touchState.tapCount = 0;
  }

  if (touchState.doubleTapDetected && (now - lastModeSwitch > MODE_SWITCH_COOLDOWN_MS)) {
    touchState.doubleTapDetected = false;
    lastModeSwitch = now;
    dispState.currentMode = static_cast<DisplayMode>(((int)dispState.currentMode + 1) % 3);
    applyModeChangeNow();
  }
}

void updateBrightness() {
  uint8_t target;
  switch (dispState.currentMode) {
    case DisplayMode::MODE_FORCE_DAY:   target = SETTINGS.brightness_day; break;
    case DisplayMode::MODE_FORCE_NIGHT: target = SETTINGS.brightness_night; break;
    default: target = Utils::isDaytimeByAstronomy() ? SETTINGS.brightness_day : SETTINGS.brightness_night;
  }
  
  if (M5.Display.getBrightness() != target) {
    M5.Display.setBrightness(target);
  }
}

bool shouldBeNightMode() {
  switch (dispState.currentMode) {
    case DisplayMode::MODE_FORCE_DAY: return false;
    case DisplayMode::MODE_FORCE_NIGHT: return true;
    default: return !Utils::isDaytimeByAstronomy();
  }
}

void showErrorScreen() {
  String msg = "ERREUR INCONNUE";
  switch (sysState.type) {
    case SystemStateType::STATE_NO_WIFI: msg = "CONNEXION WIFI PERDUE"; break;
    case SystemStateType::STATE_NO_MQTT: msg = "CONNEXION MQTT PERDUE"; break;
    case SystemStateType::STATE_NO_DATA: msg = "AUCUNE DONNEE RECUE"; break;
    default: break;
  }
  
  M5.Display.fillScreen(TFT_RED);
  M5.Display.setTextColor(TFT_WHITE);
  M5.Display.setTextDatum(textdatum_t::middle_center);
  M5.Display.setFont(&fonts::FreeSansBold12pt7b);
  M5.Display.drawString(msg, 160, 120);
}

void updateAlertIcons() {
    bool current_wind_state = g_weather.valid && 
                              (g_weather.maxWindTodayKmh >= SETTINGS.wind_alert_threshold_kmh || 
                               g_weather.maxGustTodayKmh >= SETTINGS.gust_alert_threshold_kmh);

    bool current_humidity_state = (sensorData.studioHumidity > SETTINGS.humidity_alert_threshold);
    
    bool current_flame_state = (sensorData.temp_onduleur1 > SETTINGS.inverter_temp_alert_threshold_c) ||
                               (sensorData.temp_onduleur2 > SETTINGS.inverter_temp_alert_threshold_c);

    bool state_changed = (current_wind_state != last_wind_state) ||
                         (current_humidity_state != last_humidity_state) ||
                         (current_flame_state != last_flame_state);

    if (state_changed || dispState.needsRedraw) {
        if(bg_buffer_icons) M5.Display.pushImage(ICON_AREA_X, ICON_AREA_Y, ICON_AREA_W, ICON_AREA_H, bg_buffer_icons);

        int current_y = ICON_AREA_Y;

        if (current_wind_state && !g_wind_icon_png.empty()) {
            M5.Display.drawPng(g_wind_icon_png.data(), g_wind_icon_png.size(), ICON_AREA_X, current_y);
            current_y += ICON_WIDTH + ICON_SPACING;
        }
        if (current_humidity_state && !g_humidity_icon_png.empty()) {
            M5.Display.drawPng(g_humidity_icon_png.data(), g_humidity_icon_png.size(), ICON_AREA_X, current_y);
            current_y += ICON_WIDTH + ICON_SPACING;
        }
        if (current_flame_state && !g_flame_icon_png.empty()) {
            M5.Display.drawPng(g_flame_icon_png.data(), g_flame_icon_png.size(), ICON_AREA_X, current_y);
        }
        
        last_wind_state = current_wind_state;
        last_humidity_state = current_humidity_state;
        last_flame_state = current_flame_state;
    }
}

} // namespace UI


// --- Internal Functions ---

static void readAllBackgroundBuffers() {
  M5.Display.readRect(HEURE_DISP_X - (HEURE_DISP_W / 2), HEURE_DISP_Y - (HEURE_DISP_H / 2), HEURE_DISP_W, HEURE_DISP_H, bg_buffer_heure);
  M5.Display.readRect(MAISON_DISP_X - (MAISON_DISP_W / 2), MAISON_DISP_Y - (MAISON_DISP_H / 2), MAISON_DISP_W, MAISON_DISP_H, bg_buffer_maison);
  M5.Display.readRect(PV_DISP_X - (PV_DISP_W / 2), PV_DISP_Y - (PV_DISP_H / 2), PV_DISP_W, PV_DISP_H, bg_buffer_pv);
  M5.Display.readRect(GRID_DISP_X - (GRID_DISP_W / 2), GRID_DISP_Y - (GRID_DISP_H / 2), GRID_DISP_W, GRID_DISP_H, bg_buffer_grid);
  M5.Display.readRect(EAU_DISP_X - (EAU_DISP_W / 2), EAU_DISP_Y - (EAU_DISP_H / 2), EAU_DISP_W, EAU_DISP_H, bg_buffer_eau);
  M5.Display.readRect(PISCINE_DISP_X - (PISCINE_DISP_W / 2), PISCINE_DISP_Y - (PISCINE_DISP_H / 2), PISCINE_DISP_W, PISCINE_DISP_H, bg_buffer_piscine);
  M5.Display.readRect(PAC_DISP_X - (PAC_DISP_W / 2), PAC_DISP_Y - (PAC_DISP_H / 2), PAC_DISP_W, PAC_DISP_H, bg_buffer_pac);
  M5.Display.readRect(PV_GFX_X, PV_GFX_Y, PV_GFX_W, PV_GFX_H, bg_buffer_solar);
  M5.Display.readRect(PAC_FAN_X, PAC_FAN_Y, PAC_FAN_W, PAC_FAN_H, bg_buffer_fan);
  M5.Display.readRect(WAVE_X, WAVE_Y, WAVE_W, WAVE_H, bg_buffer_wave);
  M5.Display.readRect(GRID_ARROW_X, GRID_ARROW_Y, GRID_ARROW_W, GRID_ARROW_H, bg_buffer_grid_arrow);

  if (bg_buffer_icons) M5.Display.readRect(ICON_AREA_X, ICON_AREA_Y, ICON_AREA_W, ICON_AREA_H, bg_buffer_icons);

  if (toastVisible) toastVisible = false;
}

static bool readFileToBuffer(const String& path, std::vector<uint8_t>& out) {
  File f = SD.open(path, FILE_READ);
  if (!f) return false;
  size_t len = f.size();
  if (len == 0) { f.close(); return false; }
  out.resize(len);
  f.read(out.data(), len);
  f.close();
  return true;
}

static String findBgPath(bool nightMode) {
  if (nightMode) {
    if (SD.exists("/bg_night.jpg")) return "/bg_night.jpg";
    if (SD.exists("/bg_night.jpeg")) return "/bg_night.jpeg";
  }
  if (SD.exists("/bg.jpg")) return "/bg.jpg";
  if (SD.exists("/bg.jpeg")) return "/bg.jpeg";
  return "";
}

static bool drawBackgroundFromSD(bool nightMode) {
  String p = findBgPath(nightMode);
  if (p == "") {
    DataLogging::writeLog(LogLevel::LOG_ERROR, "BG not found for " + String(nightMode ? "night" : "day") + " mode");
    return false;
  }
  
  std::vector<uint8_t> buf;
  if (!readFileToBuffer(p, buf)) return false;
  M5.Display.drawJpg(buf.data(), buf.size());
  
  dispState.isWindIconOnScreen = g_weather.valid && (g_weather.maxWindTodayKmh >= SETTINGS.wind_alert_threshold_kmh || g_weather.maxGustTodayKmh >= SETTINGS.gust_alert_threshold_kmh);
  dispState.isHumidityIconOnScreen = (sensorData.studioHumidity > SETTINGS.humidity_alert_threshold);
  dispState.isFlameIconOnScreen = (sensorData.temp_onduleur1 > SETTINGS.inverter_temp_alert_threshold_c || sensorData.temp_onduleur2 > SETTINGS.inverter_temp_alert_threshold_c);
  return true;
}

static void drawSpriteText(LGFX_Sprite& sp, int w, int h, const String& text, uint8_t font, uint16_t color, uint16_t* bg, textdatum_t datum, int x_offset) {
  sp.pushImage(0, 0, w, h, bg);
  sp.setTextFont(font);
  sp.setTextColor(color);
  sp.setTextWrap(false);
  sp.setTextDatum(datum);
  int tx = (datum == textdatum_t::middle_left) ? x_offset : w / 2;
  sp.drawString(text, tx, h / 2);
}

static void updateSolarFill() {
  if (fabs(powerData.current_pv_watts_float - last_current_pv_watts_float) < 10) return;
  last_current_pv_watts_float = powerData.current_pv_watts_float;
  
  float fill = constrain(powerData.current_pv_watts_float / SETTINGS.pv_max_watts, 0.0f, 1.0f);
  spSolar.pushImage(0, 0, PV_GFX_W, PV_GFX_H, bg_buffer_solar);
  
  if (fill > 0.01f) {
    int p0x = PV_TRAPEZE_P0_X - PV_GFX_X, p0y = PV_TRAPEZE_P0_Y - PV_GFX_Y;
    int p1x = PV_TRAPEZE_P1_X - PV_GFX_X, p2x = PV_TRAPEZE_P2_X - PV_GFX_X;
    int p3x = PV_TRAPEZE_P3_X - PV_GFX_X, p3y = PV_TRAPEZE_P3_Y - PV_GFX_Y;
    int h = p3y - p0y, fill_h = h * fill, top_y = p3y - fill_h;
    uint16_t color = TFT_GREEN;
    
    for (int y = top_y; y <= p3y; ++y) {
      float prog_y = h == 0 ? 0 : constrain((float)(y - p0y) / h, 0.0f, 1.0f);
      int start_x = p0x + (p3x - p0x) * prog_y;
      int end_x = p1x + (p2x - p1x) * prog_y;
      for (int x = start_x; x <= end_x; ++x) {
        if ((x + y) % 2 == 0) spSolar.drawPixel(x, y, color);
      }
    }
  }
  spSolar.pushSprite(PV_GFX_X, PV_GFX_Y);
}


static void updatePacAnimation() {
    // Le bloc de logging intensif a été retiré pour améliorer la stabilité.
    
    bool newS = !isnan(sensorData.pac_value_float) && 
                (sensorData.pac_value_float >= SETTINGS.pac_min_temp_c && 
                 sensorData.pac_value_float <= SETTINGS.pac_max_temp_c);

    if (newS != isPacRunning) {
        // On conserve ce log car il est utile et n'est appelé que lors d'un changement d'état.
        DataLogging::writeLog(LogLevel::LOG_INFO, "[PAC Animation] State changed to: " + String(newS ? "ON" : "OFF"));
        isPacRunning = newS;
        pacStateChanged = true;
    }

    if (isPacRunning) {
        if (millis() - lastFanFrameUpdate > FAN_FRAME_PERIOD_MS) {
            lastFanFrameUpdate = millis();
            fanFrame = 1 - fanFrame;
            if (fanFrame == 0) {
                spFan.drawPng(fan_frame1_png, sizeof(fan_frame1_png));
            } else {
                spFan.drawPng(fan_frame2_png, sizeof(fan_frame2_png));
            }
            spFan.pushSprite(PAC_FAN_X, PAC_FAN_Y);
        }
    } else if (pacStateChanged) {
        spFan.pushImage(0, 0, PAC_FAN_W, PAC_FAN_H, bg_buffer_fan);
        spFan.pushSprite(PAC_FAN_X, PAC_FAN_Y);
        pacStateChanged = false;
    }
}

static void updatePoolWaves() {
  if (sensorData.isPumpRunning != (lastWaveUpdate != 0)) {
    pumpStateChanged = true;
    lastWaveUpdate = sensorData.isPumpRunning ? millis() : 0;
  }
  if (!sensorData.isPumpRunning) {
    if (pumpStateChanged) {
      spWave.pushImage(0, 0, WAVE_W, WAVE_H, bg_buffer_wave);
      spWave.pushSprite(WAVE_X, WAVE_Y);
      pumpStateChanged = false;
    }
    return;
  }
  if (millis() - lastWaveUpdate < WAVE_PERIOD_MS) return;
  lastWaveUpdate = millis();
  
  spWave.pushImage(0, 0, WAVE_W, WAVE_H, bg_buffer_wave);
  int r = random(0, 3);
  const auto* src = (r == 0) ? &wave1_png : (r == 1) ? &wave2_png : &wave3_png;
  if (src && !src->empty()) spWave.drawPng(src->data(), src->size());
  spWave.pushSprite(WAVE_X, WAVE_Y);
}

static void updateGridArrow() {
  if (powerData.isGridPositive != (lastGridArrowUpdate != 0)) {
    gridArrowStateChanged = true;
    lastGridArrowUpdate = powerData.isGridPositive ? millis() : 0;
  }
  if (!powerData.isGridPositive) {
    if (gridArrowStateChanged) {
      spGridArrow.pushImage(0, 0, GRID_ARROW_W, GRID_ARROW_H, bg_buffer_grid_arrow);
      spGridArrow.pushSprite(GRID_ARROW_X, GRID_ARROW_Y);
      gridArrowStateChanged = false;
    }
    return;
  }
  if (millis() - lastGridArrowUpdate < GRID_ARROW_PERIOD_MS) return;
  lastGridArrowUpdate = millis();
  
  const auto& frame = grid_png[gridArrowFrame];
  spGridArrow.pushImage(0, 0, GRID_ARROW_W, GRID_ARROW_H, bg_buffer_grid_arrow);
  if (!frame.empty()) spGridArrow.drawPng(frame.data(), frame.size());
  spGridArrow.pushSprite(GRID_ARROW_X, GRID_ARROW_H);
  gridArrowFrame = (gridArrowFrame + 1) % 6;
}

static void showModeToast(const char* text) {
  const int TOAST_W = 84, TOAST_H = 28;
  const int TOAST_X = 236, TOAST_Y = 25;
  if (bg_buffer_toast == nullptr) bg_buffer_toast = (uint16_t*)malloc(TOAST_W * TOAST_H * sizeof(uint16_t));
  if (bg_buffer_toast) M5.Display.readRect(TOAST_X, TOAST_Y, TOAST_W, TOAST_H, bg_buffer_toast);
  
  M5.Display.fillRoundRect(TOAST_X, TOAST_Y, TOAST_W, TOAST_H, 6, M5.Display.color565(20, 20, 20));
  M5.Display.drawRoundRect(TOAST_X, TOAST_Y, TOAST_W, TOAST_H, 6, M5.Display.color565(200, 200, 200));
  M5.Display.setTextColor(TFT_WHITE);
  M5.Display.setTextFont(2);
  M5.Display.setTextDatum(textdatum_t::middle_center);
  M5.Display.drawString(text, TOAST_X + TOAST_W / 2, TOAST_Y + TOAST_H / 2);
  
  toastVisible = true;
  toastUntil = millis() + 1500;
}

static void hideToast() {
  const int TOAST_W = 84, TOAST_H = 28;
  const int TOAST_X = 236, TOAST_Y = 25;
  if (!toastVisible) return;
  if (bg_buffer_toast) M5.Display.pushImage(TOAST_X, TOAST_Y, TOAST_W, TOAST_H, bg_buffer_toast);
  toastVisible = false;
}

static void maybeUpdateToast() {
  if (toastVisible && (long)(millis() - toastUntil) >= 0) {
    hideToast();
  }
}

// Structure pour définir un point de couleur dans le dégradé
struct ColorStop {
    float watts;
    uint8_t r, g, b;
};

const ColorStop gradient[] = {
    {0,      0,   255, 0  },
    {2000,   255, 215, 0  },
    {5000,   255, 0,   0  },
    {8000,   128, 0,   128},
    {10000,  0,   0,   0  }
};
const int numStops = sizeof(gradient) / sizeof(gradient[0]);


static uint16_t getPowerGradientColor(float watts) {
    if (watts < 0) watts = 0;

    if (watts >= gradient[numStops - 1].watts) {
        const auto& lastStop = gradient[numStops - 1];
        return M5.Display.color565(lastStop.r, lastStop.g, lastStop.b);
    }

    for (int i = 0; i < numStops - 1; ++i) {
        const auto& start = gradient[i];
        const auto& end = gradient[i + 1];

        if (watts >= start.watts && watts <= end.watts) {
            float ratio = (end.watts == start.watts) ? 0 : (watts - start.watts) / (end.watts - start.watts);
            uint8_t r = start.r + (end.r - start.r) * ratio;
            uint8_t g = start.g + (end.g - start.g) * ratio;
            uint8_t b = start.b + (end.b - start.b) * ratio;
            return M5.Display.color565(r, g, b);
        }
    }
    
    return M5.Display.color565(gradient[0].r, gradient[0].g, gradient[0].b);
}
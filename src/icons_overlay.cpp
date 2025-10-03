#include "icons_overlay.h"
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>

namespace IconsOverlay {
static TaskHandle_t task = nullptr;
static uint16_t* bg = nullptr;
static bool lastNight = false;
static Screen lastScreen = Screen::SCREEN_DASHBOARD;
static unsigned long lastDraw = 0;

static const int ICON_X = 5;
static const int ICON_Y = 5;
static const int ICON_SIZE = 32;
static const int ICON_AREA_W = 40;
static const int ICON_AREA_H = ICON_SIZE * 3 + 5;
static const unsigned long INTERVAL_MS = 1000;

static bool computeWind() {
  return g_weather.valid &&
         (g_weather.maxWindTodayKmh >= SETTINGS.wind_alert_threshold_kmh ||
          g_weather.maxGustTodayKmh >= SETTINGS.gust_alert_threshold_kmh);
}
static bool computeHumidity() {
  return sensorData.studioHumidity > SETTINGS.humidity_alert_threshold;
}
static bool computeFlame() {
  return (sensorData.temp_onduleur1 > SETTINGS.inverter_temp_alert_threshold_c) ||
         (sensorData.temp_onduleur2 > SETTINGS.inverter_temp_alert_threshold_c);
}

static void captureBG() {
  if (!bg) bg = (uint16_t*)malloc(ICON_AREA_W * ICON_AREA_H * sizeof(uint16_t));
  if (bg) M5.Display.readRect(ICON_X, ICON_Y, ICON_AREA_W, ICON_AREA_H, bg);
}
static void restoreBG() {
  if (bg) M5.Display.pushImage(ICON_X, ICON_Y, ICON_AREA_W, ICON_AREA_H, bg);
}

static void drawIcons(bool force) {
  static bool lastWind = false, lastHum = false, lastFlame = false;
  bool w = computeWind(), h = computeHumidity(), f = computeFlame();
  bool changed = force || w != lastWind || h != lastHum || f != lastFlame;
  if (!changed && (millis() - lastDraw) < INTERVAL_MS) return;
  restoreBG();
  int y = ICON_Y;
  if (w && !g_wind_icon_png.empty()) { M5.Display.drawPng(g_wind_icon_png.data(), g_wind_icon_png.size(), ICON_X, y); y += ICON_SIZE; }
  if (h && !g_humidity_icon_png.empty()) { M5.Display.drawPng(g_humidity_icon_png.data(), g_humidity_icon_png.size(), ICON_X, y); y += ICON_SIZE; }
  if (f && !g_flame_icon_png.empty()) { M5.Display.drawPng(g_flame_icon_png.data(), g_flame_icon_png.size(), ICON_X, y); }
  lastWind = w; lastHum = h; lastFlame = f;
  dispState.isWindIconOnScreen = w; dispState.isHumidityIconOnScreen = h; dispState.isFlameIconOnScreen = f;
  lastDraw = millis();
}

static void taskLoop(void*) {
  vTaskDelay(pdMS_TO_TICKS(200));
  lastNight = dispState.isNightMode; lastScreen = dispState.currentScreen;
  captureBG(); drawIcons(true);
  for (;;) {
    if (dispState.currentScreen != lastScreen || dispState.isNightMode != lastNight) {
      lastNight = dispState.isNightMode; lastScreen = dispState.currentScreen;
      vTaskDelay(pdMS_TO_TICKS(50));
      captureBG(); drawIcons(true);
    } else {
      drawIcons(false);
    }
    vTaskDelay(pdMS_TO_TICKS(50));
  }
}

void start() {
  if (task) return;
  xTaskCreatePinnedToCore(taskLoop, "icons_overlay", 4096, nullptr, 1, &task, APP_CPU_NUM);
}
} // namespace IconsOverlay

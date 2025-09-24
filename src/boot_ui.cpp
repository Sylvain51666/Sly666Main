// src/boot_ui.cpp
#include "boot_ui.h"
#include <M5Unified.h>
#include <cmath>

namespace BootUI {

// --- Variables d'état internes au module ---
static int current_percent = -1;
static String current_label = "";
static unsigned long last_spinner_update = 0;
static int spinner_step = 0;
static bool s_active = false;

// --- Constantes de mise en page ---
constexpr int BAR_WIDTH = 220;
constexpr int BAR_HEIGHT = 12;
constexpr int BAR_X = (320 - BAR_WIDTH) / 2;
constexpr int BAR_Y = 125;
constexpr int SPINNER_RADIUS = 12;
constexpr int SPINNER_Y = BAR_Y - 35;

static void drawProgressBar(int percent) {
  if (percent < 0) percent = 0;
  if (percent > 100) percent = 100;
  if (percent == current_percent) return;

  int old_fill_width = (current_percent * BAR_WIDTH) / 100;
  int new_fill_width = (percent * BAR_WIDTH) / 100;

  if (new_fill_width < old_fill_width) {
    old_fill_width = 0;
    M5.Display.fillRoundRect(BAR_X, BAR_Y, BAR_WIDTH, BAR_HEIGHT, 3, TFT_BLACK);
    M5.Display.drawRoundRect(BAR_X, BAR_Y, BAR_WIDTH, BAR_HEIGHT, 3, TFT_WHITE);
  }

  uint8_t r_start = 64, g_start = 64, b_start = 64;
  uint8_t r_end = 192, g_end = 192, b_end = 192;
  for (int x = old_fill_width; x < new_fill_width; ++x) {
    float ratio = (float)x / (float)BAR_WIDTH;
    uint8_t r = r_start + (r_end - r_start) * ratio;
    uint8_t g = g_start + (g_end - g_start) * ratio;
    uint8_t b = b_start + (b_end - b_start) * ratio;
    M5.Display.drawFastVLine(BAR_X + x, BAR_Y + 1, BAR_HEIGHT - 2, M5.Display.color565(r, g, b));
  }

  current_percent = percent;
}

static void drawPercentText(int percent) {
  M5.Display.setTextFont(2);
  M5.Display.setTextDatum(top_center);
  M5.Display.setTextColor(TFT_WHITE, TFT_BLACK);
  M5.Display.fillRect(BAR_X, BAR_Y + BAR_HEIGHT + 5, BAR_WIDTH, 20, TFT_BLACK);
  M5.Display.drawString(String(percent) + "%", 160, BAR_Y + BAR_HEIGHT + 5);
}

static void drawLabel(const String& label) {
  current_label = label;
  M5.Display.setTextFont(2);
  M5.Display.setTextDatum(top_center);
  M5.Display.setTextColor(TFT_SILVER, TFT_BLACK);
  M5.Display.fillRect(0, BAR_Y + BAR_HEIGHT + 30, 320, 20, TFT_BLACK);
  M5.Display.drawString(current_label, 160, BAR_Y + BAR_HEIGHT + 30);
}

static void drawSpinner() {
  const int num_dots = 12;
  for (int i = 0; i < num_dots; ++i) {
    float angle = (float)i * (2.0f * PI / num_dots);
    int x = 160 + SPINNER_RADIUS * cos(angle);
    int y = SPINNER_Y + SPINNER_RADIUS * sin(angle);
    int distance = abs(i - (spinner_step % num_dots));
    if (distance > num_dots / 2) distance = num_dots - distance;
    int brightness = 255 - (distance * (255 / (num_dots / 2)));
    brightness = max(20, brightness);
    M5.Display.fillCircle(x, y, 2, M5.Display.color565(brightness, brightness, brightness));
  }
}

void begin() {
  M5.Display.fillScreen(TFT_BLACK);
  M5.Display.setTextFont(4);
  M5.Display.setTextDatum(bottom_center);
  M5.Display.setTextColor(TFT_WHITE, TFT_BLACK);
  M5.Display.drawRoundRect(BAR_X, BAR_Y, BAR_WIDTH, BAR_HEIGHT, 3, TFT_WHITE);
  setProgress(0, "Initialisation...");
  s_active = true;
}

void setProgress(int percent, const String& label) {
  drawProgressBar(percent);
  drawPercentText(percent);
  drawLabel(label);
}

void setStep(int stepIndex, int stepCount, const String& label) {
  if (stepCount == 0) return;
  int percent = (stepIndex * 100) / stepCount;
  setProgress(percent, label);
}

void loop() {
  if (millis() - last_spinner_update > 80) {
    spinner_step++;
    drawSpinner();
    last_spinner_update = millis();
  }
}

void drawVersion(const char* version) {
  M5.Display.setTextFont(2);
  M5.Display.setTextDatum(top_left);
  M5.Display.setTextColor(TFT_DARKGREY, TFT_BLACK);
  M5.Display.drawString(version, 5, 5);
}

bool isActive() { return s_active; }
void setActive(bool active) { s_active = active; }

} // namespace BootUI

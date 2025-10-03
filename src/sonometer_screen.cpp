#include <M5Unified.h>
#include "config.h"
#include "state.h"
#include "settings.h"
#include "sonometer_screen.h"
#include "network.h"
#include <ArduinoJson.h>
#include <cmath>

namespace Sonometer {

const float PEAK_DISPLAY_THRESHOLD_DB = 8.0f;
const int PEAK_DISPLAY_DURATION_MS = 750;
const unsigned long PEAK_HOLD_RESET_INTERVAL_MS = 600000;

static bool active = false;
static int16_t micBuf[512];
static unsigned long tWindowMs = 500;
static unsigned long tLast = 0;
static double sumSquares = 0.0;
static size_t countSamples = 0;
static int16_t maxAbsoluteSample = 0;
static float dbRms = 0.0f, dbPeak = 0.0f, smoothedDbRms = 40.0f;
static float hpf_y = 0.0f, hpf_x1 = 0.0f;
static double laeqEnergySum = 0.0;
static uint32_t laeqSampleCount = 0;
static uint32_t laeqStartTimeMs = 0;
static float lastLaeqValue = NAN;
static float peakHoldDb = 0.0f;
static unsigned long peakHoldStartTimeMs = 0;
static int lastDisplayInt = -9999;
static bool lastDisplayWasPeak = false;
static unsigned long peakDisplayEndTime = 0;
static float peakValueToDisplay = 0.0f;
static unsigned long last_footer_update = 0;

static uint32_t HSBtoRGB(float h, float s, float v) {
  float r, g, b;
  int i = (int)floorf(h * 6.0f);
  float f = h * 6.0f - i;
  float p = v * (1.0f - s);
  float q = v * (1.0f - f * s);
  float t = v * (1.0f - (1.0f - f) * s);

  switch (i % 6) {
    case 0: r=v; g=t; b=p; break;
    case 1: r=q; g=v; b=p; break;
    case 2: r=p; g=v; b=t; break;
    case 3: r=p; g=q; b=v; break;
    case 4: r=t; g=p; b=v; break;
    default: r=v; g=p; b=q; break;
  }
  return ((uint32_t)(r*255)<<16)|((uint32_t)(g*255)<<8)|((uint32_t)(b*255));
}

static uint16_t get_color_for_db(float db) {
  db = constrain(db, 40.0f, 105.0f);
  float t = (db - 40.0f) / 65.0f;
  float hue = (1.0f - t) * (120.0f / 360.0f);
  uint32_t c = HSBtoRGB(hue, 1.0f, 1.0f);
  return M5.Display.color565((c>>16)&0xFF, (c>>8)&0xFF, c&0xFF);
}

static inline float high_pass_filter(float x) {
  const float alpha = 0.995f;
  float y = alpha * (hpf_y + x - hpf_x1);
  hpf_x1 = x;
  hpf_y = y;
  return y;
}

static void publishMainValue(int value) {
  if (!Network::mqttClient.connected()) return;
  char buf[12];
  snprintf(buf, sizeof(buf), "%d", value);
  Network::mqttClient.publish(SETTINGS.topic_sonometer_db.c_str(), buf, true);
}

static void publishLaeqValue(float laeq) {
  if (isnan(laeq) || !Network::mqttClient.connected()) return;
  JsonDocument doc;
  doc["laeq60"] = (int)lroundf(laeq);
  char payload[32];
  size_t n = serializeJson(doc, payload, sizeof(payload));
  if (n > 0) {
    Network::mqttClient.publish(SETTINGS.topic_sonometer_laeq60.c_str(), payload, true);
  }
}

static void resetAudioAccumulators() {
  sumSquares = 0.0;
  maxAbsoluteSample = 0;
  countSamples = 0;
}

static void drawInit() {
  M5.Display.fillScreen(TFT_BLACK);
  M5.Display.setTextDatum(middle_center);
  M5.Display.setTextColor(TFT_WHITE, TFT_BLACK);
  M5.Display.setTextFont(2);
  M5.Display.setTextSize(2);
  M5.Display.drawString("Sonometre", 160, 24);
  M5.Display.setTextSize(2);
  M5.Display.drawString("dB RMS", 160, 62);
  M5.Display.setTextSize(10);
  M5.Display.drawString("--", 160, 120);
}

void enter() {
  if (active) return;
  active = true;

  M5.Speaker.end();
  auto mc = M5.Mic.config();
  mc.sample_rate = 16000;
  M5.Mic.config(mc);
  M5.Mic.begin();

  tLast = millis();
  resetAudioAccumulators();
  smoothedDbRms = 40.0f;
  lastDisplayInt = -9999;
  lastDisplayWasPeak = false;
  laeqStartTimeMs = millis();
  laeqEnergySum = 0.0;
  laeqSampleCount = 0;
  lastLaeqValue = NAN;
  peakHoldStartTimeMs = millis();
  peakHoldDb = 0.0f;
  peakDisplayEndTime = 0;
  last_footer_update = 0;
  drawInit();
}

void update() {
  if (!active) return;
  Network::loop();

  if (M5.BtnA.wasPressed()) {
    peakHoldDb = 0.0f;
    peakHoldStartTimeMs = millis();
    last_footer_update = 0;
  }

  size_t n = M5.Mic.record(micBuf, sizeof(micBuf)/sizeof(micBuf[0]));
  for (size_t i = 0; i < n; ++i) {
    int16_t raw = micBuf[i];
    float filtered = high_pass_filter((float)raw);
    sumSquares += (double)filtered * (double)filtered;
    int16_t ar = raw >= 0 ? raw : -raw;
    if (ar > maxAbsoluteSample) maxAbsoluteSample = ar;
  }
  countSamples += n;

  const unsigned long now = millis();
  if ((now - tLast) >= tWindowMs && countSamples > 0) {
    float rms_val = sqrtf((float)(sumSquares / (double)countSamples));
    dbRms = 20.0f * log10f(rms_val / 32768.0f + 1e-12f) + SETTINGS.sonometer_mic_calibration_db_offset;
    float peak_norm = (float)maxAbsoluteSample / 32767.0f;
    dbPeak = 20.0f * log10f(peak_norm + 1e-12f) + SETTINGS.sonometer_mic_calibration_db_offset;

    if (!isfinite(dbRms)) dbRms = 0.0f;
    if (!isfinite(dbPeak)) dbPeak = 0.0f;
    dbRms = constrain(dbRms, 0.0f, 130.0f);
    dbPeak = constrain(dbPeak, 0.0f, 130.0f);

    smoothedDbRms = SETTINGS.sonometer_smoothing_factor * dbRms + (1.0f - SETTINGS.sonometer_smoothing_factor) * smoothedDbRms;

    laeqEnergySum += pow(10.0, dbRms / 10.0);
    laeqSampleCount++;

    if (dbPeak > peakHoldDb) peakHoldDb = dbPeak;
    if (dbPeak > smoothedDbRms + PEAK_DISPLAY_THRESHOLD_DB) {
      peakDisplayEndTime = now + PEAK_DISPLAY_DURATION_MS;
      peakValueToDisplay = dbPeak;
    }

    bool show_peak = now < peakDisplayEndTime;
    float value_to_display = show_peak ? peakValueToDisplay : smoothedDbRms;
    int current_int = (int)lroundf(value_to_display);

    if (current_int != lastDisplayInt || show_peak != lastDisplayWasPeak) {
      M5.Display.fillRect(0, 40, 320, 140, TFT_BLACK);
      M5.Display.setTextDatum(middle_center);
      M5.Display.setTextFont(2);
      M5.Display.setTextSize(2);
      M5.Display.setTextColor(show_peak ? TFT_YELLOW : TFT_WHITE, TFT_BLACK);
      M5.Display.drawString(show_peak ? "dB PEAK" : "dB RMS", 160, 62);
      M5.Display.setTextSize(10);
      M5.Display.setTextColor(get_color_for_db(value_to_display), TFT_BLACK);
      M5.Display.drawString(String(current_int), 160, 120);
      publishMainValue(current_int);
      lastDisplayInt = current_int;
      lastDisplayWasPeak = show_peak;
    }

    resetAudioAccumulators();
    tLast = now;
  }

  if ((now - laeqStartTimeMs) >= 60000) {
    if (laeqSampleCount > 0) {
      lastLaeqValue = 10.0f * log10f((float)(laeqEnergySum / (double)laeqSampleCount));
      publishLaeqValue(lastLaeqValue);
    }
    laeqEnergySum = 0.0;
    laeqSampleCount = 0;
    laeqStartTimeMs = now;
  }

  if ((now - peakHoldStartTimeMs) >= PEAK_HOLD_RESET_INTERVAL_MS) {
    peakHoldDb = 0.0f;
    peakHoldStartTimeMs = now;
  }

  if (now - last_footer_update > 500) {
    M5.Display.fillRect(0, 180, 320, 50, TFT_BLACK);
    M5.Display.drawFastHLine(0, 180, 320, TFT_DARKGREY);
    M5.Display.setTextFont(2);
    M5.Display.setTextSize(1);
    M5.Display.setTextDatum(top_center);
    M5.Display.setTextColor(TFT_CYAN, TFT_BLACK);
    String laeq_s = isnan(lastLaeqValue) ? "..." : String((int)lroundf(lastLaeqValue));
    String footer_text = String("LAeq: ") + laeq_s + " Peak Hold: " + String((int)lroundf(peakHoldDb));
    M5.Display.drawString(footer_text, 160, 188);
    M5.Display.setTextColor(TFT_WHITE, TFT_BLACK);
    M5.Display.drawString("A=Reset Peak B=Retour", 160, 208);
    last_footer_update = now;
  }

  if (M5.BtnB.wasPressed()) {
    exit();
    dispState.currentScreen = Screen::SCREEN_DASHBOARD;
    dispState.needsRedraw = true;
    return;
  }
}

void exit() {
  if (!active) return;
  M5.Mic.end();
  M5.Speaker.begin();
  active = false;
}

bool isActive() { return active; }

} // namespace Sonometer
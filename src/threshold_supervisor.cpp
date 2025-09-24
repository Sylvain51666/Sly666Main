#include "threshold_supervisor.h"
#include <Arduino.h>
#include "freertos/FreeRTOS.h"
#include "freertos/timers.h"

namespace ThresholdSupervisor {

// ---------- Weak default getters (override by defining functions with the same names elsewhere) ----------
extern "C" float __attribute__((weak)) SUP_getWindLowKmh() { return 25.0f; }
extern "C" float __attribute__((weak)) SUP_getWindHighKmh() { return 45.0f; }
extern "C" float __attribute__((weak)) SUP_getFlammeThreshold() { return 1.0f; } // unit-less default
extern "C" uint32_t __attribute__((weak)) SUP_getNowEpoch() { return (uint32_t)(millis() / 1000UL); }
// By default, no external flame source. Return -INFINITY to skip eval.
extern "C" float __attribute__((weak)) SUP_getFlammeValue() { return -INFINITY; }

// Pointers that can be set at runtime; fallback to weak defaults.
static float (*s_getWindLowKmh)() = SUP_getWindLowKmh;
static float (*s_getWindHighKmh)() = SUP_getWindHighKmh;
static float (*s_getFlammeThreshold)() = SUP_getFlammeThreshold;
static uint32_t (*s_getNowEpoch)() = SUP_getNowEpoch;
static float (*s_getFlammeValue)() = SUP_getFlammeValue;

void setGetters(float (*getWindLowKmh)(),
                float (*getWindHighKmh)(),
                float (*getFlammeThreshold)(),
                uint32_t (*getNowEpoch)(),
                float (*getFlammeValue)()) {
  if (getWindLowKmh) s_getWindLowKmh = getWindLowKmh;
  if (getWindHighKmh) s_getWindHighKmh = getWindHighKmh;
  if (getFlammeThreshold) s_getFlammeThreshold = getFlammeThreshold;
  if (getNowEpoch) s_getNowEpoch = getNowEpoch;
  if (getFlammeValue) s_getFlammeValue = getFlammeValue;
}

// ---------- Internal state ----------
static TimerHandle_t s_timer = nullptr;

static volatile bool s_goutteOn = false;
static volatile bool s_flammeOn = false;
static volatile uint8_t s_windLevel = 0;
static volatile uint32_t s_lastWindTs = 0;

static uint32_t s_cntWindEvaluations = 0;
static uint32_t s_cntGoutteEvaluations = 0;
static uint32_t s_cntFlammeEvaluations = 0;
static uint32_t s_cntStateChanges = 0;

// Protect multi-field atomic snapshots
static portMUX_TYPE s_mux = portMUX_INITIALIZER_UNLOCKED;

// Optional callback on change
static void (*s_onChange)(const States&) = nullptr;

static void notifyIfChanged(bool oldG, bool oldF, uint8_t oldW, uint32_t oldTs) {
  bool changed = (oldG != s_goutteOn) || (oldF != s_flammeOn) || (oldW != s_windLevel) || (oldTs != s_lastWindTs);
  if (!changed) return;
  s_cntStateChanges++;
  if (s_onChange) {
    States st;
    portENTER_CRITICAL(&s_mux);
    st.goutteOn = s_goutteOn;
    st.flammeOn = s_flammeOn;
    st.windLevel = s_windLevel;
    st.lastWindTs = s_lastWindTs;
    portEXIT_CRITICAL(&s_mux);
    // Call outside critical section, still inside timer or caller context. Keep fast.
    s_onChange(st);
  }
}

void setOnStateChange(void (*cb)(const States&)) {
  s_onChange = cb;
}

// ---------- Flame evaluation (10 s) ----------
static void evalFlammeOnce() {
  s_cntFlammeEvaluations++;
  float v = s_getFlammeValue ? s_getFlammeValue() : SUP_getFlammeValue();
  if (!isfinite(v)) return; // No data, skip
  float thr = s_getFlammeThreshold ? s_getFlammeThreshold() : SUP_getFlammeThreshold();

  portENTER_CRITICAL(&s_mux);
  bool oldG = s_goutteOn;
  bool oldF = s_flammeOn;
  uint8_t oldW = s_windLevel;
  uint32_t oldTs = s_lastWindTs;

  bool newF = (v >= thr);
  s_flammeOn = newF;
  portEXIT_CRITICAL(&s_mux);

  notifyIfChanged(oldG, oldF, oldW, oldTs);
}

static void vTimerCallback(TimerHandle_t) {
  evalFlammeOnce();
}

void begin() {
  if (s_timer) return;
  // 10 s period, auto-reload
  s_timer = xTimerCreate("thr_sup", pdMS_TO_TICKS(10000), pdTRUE, nullptr, vTimerCallback);
  if (s_timer) xTimerStart(s_timer, 0);
}

// ---------- Event-driven inputs ----------
void onWindFetch(float windKmh, uint32_t fetchEpochSeconds) {
  s_cntWindEvaluations++;

  float low = s_getWindLowKmh ? s_getWindLowKmh() : SUP_getWindLowKmh();
  float high = s_getWindHighKmh ? s_getWindHighKmh() : SUP_getWindHighKmh();
  if (high < low) { float t = high; high = low; low = t; }

  uint8_t level = 0;
  if (windKmh >= high) level = 2;
  else if (windKmh >= low) level = 1;
  else level = 0;

  // Stale guard: if supplied timestamp looks older than 3600 s vs now, ignore
  uint32_t now = s_getNowEpoch ? s_getNowEpoch() : SUP_getNowEpoch();
  if (fetchEpochSeconds != 0 && now > fetchEpochSeconds && (now - fetchEpochSeconds) > 3600UL) {
    return; // stale data, do not change
  }

  portENTER_CRITICAL(&s_mux);
  bool oldG = s_goutteOn;
  bool oldF = s_flammeOn;
  uint8_t oldW = s_windLevel;
  uint32_t oldTs = s_lastWindTs;

  s_windLevel = level;
  s_lastWindTs = (fetchEpochSeconds ? fetchEpochSeconds : now);
  portEXIT_CRITICAL(&s_mux);

  notifyIfChanged(oldG, oldF, oldW, oldTs);
}

void onGoutteMqtt(int value) {
  s_cntGoutteEvaluations++;
  bool newG = (value > 0);

  portENTER_CRITICAL(&s_mux);
  bool oldG = s_goutteOn;
  bool oldF = s_flammeOn;
  uint8_t oldW = s_windLevel;
  uint32_t oldTs = s_lastWindTs;

  s_goutteOn = newG;
  portEXIT_CRITICAL(&s_mux);

  notifyIfChanged(oldG, oldF, oldW, oldTs);
}

// ---------- Public getters ----------
States getStates() {
  States st;
  portENTER_CRITICAL(&s_mux);
  st.goutteOn = s_goutteOn;
  st.flammeOn = s_flammeOn;
  st.windLevel = s_windLevel;
  st.lastWindTs = s_lastWindTs;
  portEXIT_CRITICAL(&s_mux);
  return st;
}

Telemetry getTelemetry() {
  Telemetry t;
  t.cntWindEvaluations = s_cntWindEvaluations;
  t.cntGoutteEvaluations = s_cntGoutteEvaluations;
  t.cntFlammeEvaluations = s_cntFlammeEvaluations;
  t.cntStateChanges = s_cntStateChanges;
  return t;
}

} // namespace ThresholdSupervisor

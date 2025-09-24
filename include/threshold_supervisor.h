#pragma once
#include <stdint.h>

// Lightweight threshold supervisor for wind, droplet, and flame.
// No blocking calls. Uses a FreeRTOS software timer for flame polling every 10 s.
// Thresholds remain dynamic and external. This module only compares values to thresholds.
// Integration points are function pointers you can set at runtime or weak default getters you can override.

namespace ThresholdSupervisor {

// Wind level: 0 = below low, 1 = between low and high, 2 = >= high
struct States {
  bool goutteOn;
  bool flammeOn;
  uint8_t windLevel;    // 0..2
  uint32_t lastWindTs;  // epoch seconds of the last wind fetch processed
};

// Configure external getters. All are optional. If not set, weak defaults are used.
// getWindLowKmh: returns current "low" wind threshold in km/h
// getWindHighKmh: returns current "high" wind threshold in km/h
// getFlammeThreshold: returns the flame threshold (unit consistent with your source value)
// getNowEpoch: returns current epoch seconds
// getFlammeValue: returns current flame source value
void setGetters(float (*getWindLowKmh)(),
                float (*getWindHighKmh)(),
                float (*getFlammeThreshold)(),
                uint32_t (*getNowEpoch)(),
                float (*getFlammeValue)());

// Optional state change callback. Called from timer context. Keep it fast.
void setOnStateChange(void (*cb)(const States&));

// Start supervisor. Creates and starts a 10 s FreeRTOS software timer for flame checks.
void begin();

// Event-driven inputs:

// Call after each successful weather fetch. Pass the interpolated "now" wind speed if you have it,
// otherwise pass the latest available forecast knot and let the thresholds decide.
void onWindFetch(float windKmh, uint32_t fetchEpochSeconds);

// Call from your MQTT callback when the droplet topic changes. "value" is your raw integer or boolean-like value.
// Comparaison rule: value > 0 => ON. If you need a thresholded analog, just call with (value >= threshold).
void onGoutteMqtt(int value);

// Read current stable states. Thread-safe.
States getStates();

// Telemetry counters for debugging (read-only snapshot).
struct Telemetry {
  uint32_t cntWindEvaluations;
  uint32_t cntGoutteEvaluations;
  uint32_t cntFlammeEvaluations;
  uint32_t cntStateChanges;
};
Telemetry getTelemetry();

} // namespace ThresholdSupervisor

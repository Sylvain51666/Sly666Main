#pragma once
#include <Arduino.h>

namespace InvoicesDaily {
  // Lance le job factures en tâche FreeRTOS, avec garde anti-réentrance.
  void runAsyncDailyJob();
}

#include <Arduino.h>
#include "data_logging.h"
#include "invoices_store.h"
#include "invoices_daily_job.h"

namespace {
  static volatile bool s_busy = false;
}

namespace InvoicesDaily {

  void runAsyncDailyJob() {
    if (s_busy) {
      DataLogging::writeLog(LogLevel::LOG_WARN, "Invoices: daily update skipped (busy)");
      return;
    }
    s_busy = true;

    xTaskCreatePinnedToCore([](void*){
      DataLogging::writeLog(LogLevel::LOG_INFO, "Invoices: daily update start (async)");
      InvoicesStore::triggerDailyUpdate();
      DataLogging::writeLog(LogLevel::LOG_INFO, "Invoices: daily update done");
      s_busy = false;
      vTaskDelete(nullptr);
    }, "dailyInvoices", 8192, nullptr, 1, nullptr, 0); // core 0, low prio
  }

}

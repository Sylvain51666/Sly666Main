#pragma once
#include "state.h"

namespace DataLogging {

void init();
void writeLog(LogLevel level, const String& message);
void flushLogBufferToSD();
void cleanupLogs();
size_t getLogBufferLength();

void handleTalonLogic();
void saveDailyWaterConsumption();
void loadWaterData();
void calculateWaterStats();
void startWaterStatsAsync();  // async wrapper, non-bloquant

} // namespace DataLogging
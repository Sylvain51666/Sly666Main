#include "invoices_store.h"
#include "state.h"
#include "config.h"
#include "settings.h"
#include "data_logging.h"
#include "secrets.h"
#include "utils.h"
#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <SD.h>
#include <WiFi.h>

// Déclarations des fonctions statiques
static bool fetchAndProcessDay(int year, int month, int day);
static void archivePreviousMonth();
static void saveDebugData();
static void loadParams();
static void updateTotalsFromProcessedFiles();
static bool processDay(int year, int month, int day);
static void nextDay(int y, int m, int d, int& ny, int& nm, int& nd);
static bool normalizeMEDtoBorisLike(const String& medBody, const char* usagePoint, const char* startStr, const char* endStr, String& outBorisJson);
static void computePeriodStarts(const struct tm& nowTm, struct tm& currStart, struct tm& prevStart);
static void sumPeriodFromProcessed(time_t startTs, time_t endTsExclusive, double& totalCost, double& hcKwh, double& hpKwh);

// Variables statiques
static String s_lastApiUrl;
static String s_lastApiMsg;
static WiFiClient g_invoiceClient;
static const char* kMedBase = "http://192.168.1.175:5000/detail";
static const char* kMeasure = "consumption";
const int MAX_FETCH_ATTEMPTS = 2;


namespace InvoicesStore {

void init() {
  loadParams();
  processExistingRawForCurrentPeriod();
  updateTotalsFromProcessedFiles();
  DataLogging::writeLog(LogLevel::LOG_INFO, "Invoices: init ok.");
}

void handleMqttMessage(const String& topic, const String& payload) {
  bool updated = false;
  float val = payload.toFloat();
  String topicStr(topic);

  if (topicStr == SETTINGS.topic_sub_price && val > 0) { 
      g_invoiceParams.monthlySubscription = val; updated = true; 
  } else if (topicStr == SETTINGS.topic_hc_price && val > 0) { 
      g_invoiceParams.priceHc = val; updated = true; 
  } else if (topicStr == SETTINGS.topic_hp_price && val > 0) { 
      g_invoiceParams.priceHp = val; updated = true; 
  } else if (topicStr == SETTINGS.topic_bill_day && val >= 1 && val <= 28) { 
      g_invoiceParams.billingStartDay = (int)val; updated = true; 
  }

  if (updated) {
    DataLogging::writeLog(LogLevel::LOG_INFO, "Invoices: param maj via MQTT -> " + topic + " = " + payload);
    updateTotalsFromProcessedFiles();
  }
}

void processExistingRawForCurrentPeriod() {
  struct tm nowTm;
  if (!getLocalTime(&nowTm)) {
    DataLogging::writeLog(LogLevel::LOG_ERROR, "Invoices: processExistingRaw no RTC.");
    return;
  }
  struct tm period_start_tm = nowTm;
  if (nowTm.tm_mday < g_invoiceParams.billingStartDay) {
    period_start_tm.tm_mon -= 1;
    if (period_start_tm.tm_mon < 0) { period_start_tm.tm_mon = 11; period_start_tm.tm_year -= 1; }
  }
  period_start_tm.tm_mday = g_invoiceParams.billingStartDay;
  time_t tsStart = mktime(&period_start_tm);
  time_t tsEndEx = time(nullptr) - 86400;

  for (time_t t = tsStart; t <= tsEndEx; t += 86400) {
    struct tm d;
    localtime_r(&t, &d);
    char dayStr[11], rawPath[96], processedPath[96];
    snprintf(dayStr, sizeof(dayStr), "%04d-%02d-%02d", d.tm_year + 1900, d.tm_mon + 1, d.tm_mday);
    snprintf(rawPath, sizeof(rawPath), "%s/%s.json", DAILY_JSON_DIR, dayStr);
    snprintf(processedPath, sizeof(processedPath), "%s/%s_processed.json", DAILY_JSON_DIR, dayStr);

    if (SD.exists(rawPath) && !SD.exists(processedPath)) {
      DataLogging::writeLog(LogLevel::LOG_INFO, String("Invoices: build processed for ") + dayStr);
      processDay(d.tm_year + 1900, d.tm_mon + 1, d.tm_mday);
    }
  }
  updateTotalsFromProcessedFiles();
}

void triggerDailyUpdate() {
  DataLogging::writeLog(LogLevel::LOG_INFO, "Invoices: daily update start.");
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo)) {
    DataLogging::writeLog(LogLevel::LOG_ERROR, "Invoices: getLocalTime failed for daily update.");
    return;
  }
  time_t now = time(nullptr) - 86400; // Yesterday
  localtime_r(&now, &timeinfo);
  
  if (timeinfo.tm_mday == g_invoiceParams.billingStartDay) {
    archivePreviousMonth();
  }
  if (fetchAndProcessDay(timeinfo.tm_year + 1900, timeinfo.tm_mon + 1, timeinfo.tm_mday)) {
    updateTotalsFromProcessedFiles();
  }
}

void checkContinuityAndRefetch() {
  DataLogging::writeLog(LogLevel::LOG_INFO, "Invoices: continuity check start.");
  triggerDailyUpdate();
}

} // namespace InvoicesStore


static void loadParams() {
    g_invoiceParams.priceHp             = DEFAULT_PRICE_HP;
    g_invoiceParams.priceHc             = DEFAULT_PRICE_HC;
    g_invoiceParams.monthlySubscription = DEFAULT_ABO_MOIS;
    g_invoiceParams.billingStartDay     = DEFAULT_BILLING_DAY;
    DataLogging::writeLog(LogLevel::LOG_INFO, "Invoices: default params loaded as fallback.");
}

static void updateTotalsFromProcessedFiles() {
  DataLogging::writeLog(LogLevel::LOG_INFO, "Invoices: rebuild totals from processed files...");

  InvoiceData fresh{};
  struct tm nowTm;
  if (!getLocalTime(&nowTm)) return;
  
  struct tm period_start_tm = nowTm;
  if (nowTm.tm_mday < g_invoiceParams.billingStartDay) {
    period_start_tm.tm_mon -= 1;
    if (period_start_tm.tm_mon < 0) { period_start_tm.tm_mon = 11; period_start_tm.tm_year -= 1; }
  }
  period_start_tm.tm_mday = g_invoiceParams.billingStartDay;
  time_t start_of_period = mktime(&period_start_tm);

  time_t end_of_loop_exclusive = time(nullptr);

  for (time_t t = start_of_period; t < end_of_loop_exclusive; t += 86400) {
    struct tm d;
    localtime_r(&t, &d);
    char path[96];
    snprintf(path, sizeof(path), "%s/%04d-%02d-%02d_processed.json", DAILY_JSON_DIR, d.tm_year + 1900, d.tm_mon + 1, d.tm_mday);

    File f = SD.open(path);
    if (!f) continue;
    
    JsonDocument doc;
    if (deserializeJson(doc, f) == DeserializationError::Ok) {
      fresh.currentBillingTotal     += doc["cost_eur"] | 0.0;
      fresh.totalHcKwh              += doc["hc_kwh"]   | 0.0;
      fresh.totalHpKwh              += doc["hp_kwh"]   | 0.0;
      fresh.accumulatedSubscription += doc["subscription_daily_eur"] | 0.0;
    }
    f.close();
  }
  g_invoiceData = fresh;
}

static bool processDay(int year, int month, int day) {
  char dayStr[11];
  snprintf(dayStr, sizeof(dayStr), "%04d-%02d-%02d", year, month, day);
  char path_raw[96];
  snprintf(path_raw, sizeof(path_raw), "%s/%s.json", DAILY_JSON_DIR, dayStr);

  File rawFile = SD.open(path_raw);
  if (!rawFile) return false;

  JsonDocument doc;
  if (deserializeJson(doc, rawFile) != DeserializationError::Ok) {
    rawFile.close();
    return false;
  }
  rawFile.close();

  if (!doc["interval_reading"].is<JsonArray>()) {
    return false;
  }
  JsonArray arr = doc["interval_reading"];

  double kWh_HP = 0.0, kWh_HC = 0.0;
  for (JsonObject v : arr) {
    long W = atol((const char*)(v["value"] | "0"));
    const char* dstr = v["date"] | "";
    int hh = (strlen(dstr) >= 16) ? ((dstr[11] - '0') * 10 + (dstr[12] - '0')) : 0;
    double kWh = W * 0.5 / 1000.0;
    bool isHC = (hh < HC_END_HOUR);
    if (isHC) kWh_HC += kWh; else kWh_HP += kWh;
  }

  int nbj = Utils::daysInMonth(year, month);
  double subscription_daily_eur = (nbj > 0) ? (g_invoiceParams.monthlySubscription / nbj) : 0.0;
  double cost_eur = (kWh_HC * g_invoiceParams.priceHc) + (kWh_HP * g_invoiceParams.priceHp) + subscription_daily_eur;

  char path_processed[96];
  snprintf(path_processed, sizeof(path_processed), "%s/%s_processed.json", DAILY_JSON_DIR, dayStr);
  
  File processedFile = SD.open(path_processed, FILE_WRITE);
  if (processedFile) {
    JsonDocument pd;
    pd["cost_eur"] = cost_eur;
    pd["hc_kwh"]   = kWh_HC;
    pd["hp_kwh"]   = kWh_HP;
    pd["subscription_daily_eur"] = subscription_daily_eur;
    serializeJson(pd, processedFile);
    processedFile.close();
  } else {
    return false;
  }
  saveDebugData();
  return true;
}

static bool fetchAndProcessDay(int year, int month, int day) {
  char startStr[11], endStr[11], dayStr[11];
  snprintf(dayStr, sizeof(dayStr), "%04d-%02d-%02d", year, month, day);
  strcpy(startStr, dayStr);
  int ny, nm, nd;
  nextDay(year, month, day, ny, nm, nd);
  snprintf(endStr, sizeof(endStr), "%04d-%02d-%02d", ny, nm, nd);
  String url = String(kMedBase) + "/" + PRM_NUMBER + "/" + kMeasure + "/" + startStr + "/" + endStr;
  s_lastApiUrl = url;
  s_lastApiMsg = "";

  HTTPClient http;
  if (!http.begin(g_invoiceClient, url)) return false;

  int httpCode = http.GET();
  g_invoiceData.lastApiHttpCode = httpCode;
  if (httpCode != HTTP_CODE_OK) {
    http.end();
    g_invoiceData.lastApiError = "HTTP " + String(httpCode);
    saveDebugData();
    return false;
  }

  String normalized;
  if (!normalizeMEDtoBorisLike(http.getString(), PRM_NUMBER, startStr, endStr, normalized)) {
    http.end();
    g_invoiceData.lastApiError = "normalize_failed";
    saveDebugData();
    return false;
  }
  http.end();

  char rawPath[128];
  snprintf(rawPath, sizeof(rawPath), "%s/%s.json", DAILY_JSON_DIR, dayStr);
  File f = SD.open(rawPath, FILE_WRITE);
  if (!f) return false;
  f.print(normalized);
  f.close();

  g_invoiceData.lastUpdateTimestamp = time(nullptr);
  g_invoiceData.lastApiError = "OK";
  s_lastApiMsg = "OK";
  saveDebugData();

  return processDay(year, month, day);
}

static void saveDebugData() {
  File f = SD.open(DEBUG_DATA_FILE, FILE_WRITE);
  if (!f) return;
  JsonDocument doc;
  doc["lastUpdateTs"]    = (uint32_t)g_invoiceData.lastUpdateTimestamp;
  doc["lastApiError"]    = g_invoiceData.lastApiError;
  doc["lastHttpCode"]    = g_invoiceData.lastApiHttpCode;
  doc["currentTotalEur"] = g_invoiceData.currentBillingTotal;
  if (s_lastApiUrl.length()) doc["lastUrl"] = s_lastApiUrl;
  if (s_lastApiMsg.length()) doc["lastMsg"] = s_lastApiMsg;
  serializeJson(doc, f);
  f.close();
}

static bool normalizeMEDtoBorisLike(const String& medBody, const char* usagePoint, const char* startStr, const char* endStr, String& outBorisJson) {
  JsonDocument src;
  if (deserializeJson(src, medBody) != DeserializationError::Ok) return false;
  if (src["data"].isNull()) return false;

  JsonDocument out;
  out["usage_point_id"] = usagePoint;
  out["start"] = startStr;
  out["end"]   = endStr;
  JsonObject rt = out["reading_type"].to<JsonObject>();
  rt["unit"] = "W"; rt["measurement_kind"] = "power"; rt["aggregate"] = "average";

  JsonArray arr = out["interval_reading"].to<JsonArray>();
  for (JsonPair kv : src["data"].as<JsonObject>()) {
    JsonObject it = arr.add<JsonObject>();
    it["value"] = kv.value().as<String>();
    String dateStr = kv.key().c_str();
    dateStr.replace("T", " ");
    it["date"] = dateStr;
    it["interval_length"] = "PT30M";
  }
  serializeJson(out, outBorisJson);
  return true;
}

// CORRECTION : Implémentation de la fonction
static void nextDay(int y, int m, int d, int& ny, int& nm, int& nd) {
  struct tm t = {0};
  t.tm_year = y - 1900; t.tm_mon = m - 1; t.tm_mday = d;
  time_t ts = mktime(&t) + 86400; // Ajoute un jour en secondes
  localtime_r(&ts, &t);
  ny = t.tm_year + 1900;
  nm = t.tm_mon + 1;
  nd = t.tm_mday;
}

static void computePeriodStarts(const struct tm& nowTm, struct tm& currStart, struct tm& prevStart) {
  currStart = nowTm;
  if (nowTm.tm_mday < g_invoiceParams.billingStartDay) {
    currStart.tm_mon -= 1;
    if (currStart.tm_mon < 0) { currStart.tm_mon = 11; currStart.tm_year -= 1; }
  }
  currStart.tm_mday = g_invoiceParams.billingStartDay;
  mktime(&currStart);
  prevStart = currStart;
  prevStart.tm_mon -= 1;
  if (prevStart.tm_mon < 0) { prevStart.tm_mon = 11; prevStart.tm_year -= 1; }
  mktime(&prevStart);
}

static void sumPeriodFromProcessed(time_t startTs, time_t endTsExclusive, double& totalCost, double& hcKwh, double& hpKwh) {
  totalCost = hcKwh = hpKwh = 0.0;
  for (time_t ts = startTs; ts < endTsExclusive; ts += 86400) {
    struct tm d; localtime_r(&ts, &d);
    char path[96];
    snprintf(path, sizeof(path), "%s/%04d-%02d-%02d_processed.json", DAILY_JSON_DIR, d.tm_year+1900, d.tm_mon+1, d.tm_mday);
    File f = SD.open(path, FILE_READ);
    if (!f) continue;
    JsonDocument doc;
    if (deserializeJson(doc, f) == DeserializationError::Ok) {
      totalCost += (doc["cost_eur"] | 0.0);
      hcKwh     += (doc["hc_kwh"]   | 0.0);
      hpKwh     += (doc["hp_kwh"]   | 0.0);
    }
    f.close();
  }
}

static void archivePreviousMonth() {
  struct tm nowTm{}, currStart{}, prevStart{};
  if (!getLocalTime(&nowTm)) return;
  computePeriodStarts(nowTm, currStart, prevStart);

  time_t tsStart = mktime(&prevStart);
  time_t tsEndEx = mktime(&currStart);

  double tot=0.0, hc=0.0, hp=0.0;
  sumPeriodFromProcessed(tsStart, tsEndEx, tot, hc, hp);

  struct tm endIncl = currStart;
  time_t ts = mktime(&endIncl) - 86400;
  localtime_r(&ts, &endIncl);

  char sA[11], sB[11], jsonPath[128];
  snprintf(sA, sizeof(sA), "%04d-%02d-%02d", prevStart.tm_year + 1900, prevStart.tm_mon + 1, prevStart.tm_mday);
  snprintf(sB, sizeof(sB), "%04d-%02d-%02d", endIncl.tm_year + 1900, endIncl.tm_mon + 1, endIncl.tm_mday);
  snprintf(jsonPath, sizeof(jsonPath), "%s/%s_%s_summary.json", ARCHIVE_DIR, sA, sB);
  
  File jf = SD.open(jsonPath, FILE_WRITE);
  if (!jf) return;
  
  JsonDocument doc;
  doc["period_start"] = sA;
  doc["period_end"]   = sB;
  doc["total_eur"]    = tot;
  doc["hc_kwh"]       = hc;
  doc["hp_kwh"]       = hp;
  serializeJson(doc, jf);
  jf.close();
}
//==================================================
// Fichier : src/invoices_debug.cpp
//==================================================
// invoices_debug.cpp — écran debug santé API
#include "invoices_debug.h"
#include "state.h"
#include "config.h"
#include "data_logging.h"
#include <SD.h>
#include <ArduinoJson.h>

namespace InvoicesDebug {

static void logInfo(const String& s){ DataLogging::writeLog(LogLevel::LOG_INFO, "[InvoicesDebug] " + s); }
static void logWarn(const String& s){ DataLogging::writeLog(LogLevel::LOG_WARN, "[InvoicesDebug] " + s); }

static bool deserializeJsonSkippingBOM(JsonDocument& doc, File& f) {
    uint8_t bom[3]; size_t n = f.read(bom, 3);
    if (!(n == 3 && bom[0]==0xEF && bom[1]==0xBB && bom[2]==0xBF)) {
        f.seek(0);
    }
    auto err = deserializeJson(doc, f);
    if (err) { logWarn(String("deserializeJson error: ") + err.c_str()); return false; }
    return true;
}

static void loadDebugData(InvoiceData& localData) {
    File file = SD.open(DEBUG_DATA_FILE);
    if (!file) { logWarn(String("open fail: ") + DEBUG_DATA_FILE); return; }
    JsonDocument doc;
    auto err = deserializeJson(doc, file);
    file.close();
    if (err) { logWarn(String("json parse fail: ") + err.c_str()); return; }
    localData.lastUpdateTimestamp = doc["lastUpdateTs"] | 0;
    localData.lastApiError = doc["lastApiError"].as<String>();
    localData.lastApiHttpCode = doc["lastHttpCode"] | 0;
    localData.currentBillingTotal = doc["currentTotalEur"] | 0.0;
}

static bool readYesterdayCost(double& outCost, String& dateLabel) {
    time_t now = time(nullptr);
    now -= 86400; // hier
    struct tm d; localtime_r(&now, &d);
    char path[128];
    snprintf(path, sizeof(path), "%s/%04d-%02d-%02d_processed.json", DAILY_JSON_DIR,
             d.tm_year + 1900, d.tm_mon + 1, d.tm_mday);
    if (!SD.exists(path)) {
        logWarn(String("yesterday processed missing: ") + path);
        return false;
    }
    File f = SD.open(path, FILE_READ);
    if (!f) { logWarn(String("open fail: ") + path); return false; }

    JsonDocument doc;
    if (!deserializeJsonSkippingBOM(doc, f)) { f.close(); return false; }
    f.close();

    outCost = (double)(doc["cost_eur"] | 0.0);
    char buf[32];
    snprintf(buf, sizeof(buf), "%02d/%02d/%04d", d.tm_mday, d.tm_mon + 1, d.tm_year + 1900);
    dateLabel = String(buf);
    return true;
}

void generate_report(String& output) {
    InvoiceData debugState = {};
    loadDebugData(debugState);

    output += "--- FACTURATION API ---\n\n";

    char timeStr[32];
    if (debugState.lastUpdateTimestamp > 0) {
        struct tm timeinfo;
        localtime_r(&debugState.lastUpdateTimestamp, &timeinfo);
        strftime(timeStr, sizeof(timeStr), "%d/%m/%Y %H:%M:%S", &timeinfo);
        output += "Derniere maj: " + String(timeStr) + "\n";
    } else {
        output += "Derniere maj: Jamais\n";
    }

    output += "Statut API: " + debugState.lastApiError + "\n";
    output += "Dernier code HTTP: " + String(debugState.lastApiHttpCode) + "\n";
    File df = SD.open(DEBUG_DATA_FILE, FILE_READ);
    if (df) {
        JsonDocument d2;
        auto e = deserializeJson(d2, df);
        df.close();
        if (!e) {
            const char* u = d2["lastUrl"] | nullptr;
            const char* m = d2["lastMsg"] | nullptr;
            if (u && *u) output += String("Derniere URL: ") + u + "\n";
            if (m && *m) output += String("Cause: ") + m + "\n";
        }
    }
    output += "\n";
    output += "Prix: HC=" + String(g_invoiceParams.priceHc, 4) + " HP=" + String(g_invoiceParams.priceHp, 4) + "\n";
    output += "Abo: " + String(g_invoiceParams.monthlySubscription, 2) + " EUR | Jour: " + String(g_invoiceParams.billingStartDay) + "\n\n";

    double costHier = 0.0; String dateHier;
    if (readYesterdayCost(costHier, dateHier)) {
        output += "Prix hier(" + dateHier + "):" + String(costHier, 2) + " EUR\n\n";
    } else {
        output += "Prix hier: indisponible\n\n";
    }
}

} // namespace InvoicesDebug
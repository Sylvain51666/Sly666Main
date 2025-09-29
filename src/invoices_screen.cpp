//==================================================
// Fichier : src/invoices_screen.cpp
//==================================================
#include "invoices_screen.h"
#include "state.h"
#include "invoices_store.h"
#include "config.h"
#include "data_logging.h"
#include <SD.h>
#include <ArduinoJson.h>
#include <vector>
#include <cmath>

namespace InvoicesScreen {

static int s_periodOffset = 0;
static unsigned long s_toastUntil = 0;
static bool   s_currCacheValid = false;
static double s_currTotalEUR   = 0.0;
static double s_currKwh        = 0.0;

static void logInfo(const String& s){ DataLogging::writeLog(LogLevel::LOG_INFO,  "[InvoicesScreen] " + s); }
static void logWarn(const String& s){ DataLogging::writeLog(LogLevel::LOG_WARN,  "[InvoicesScreen] " + s); }
static void logError(const String& s){ DataLogging::writeLog(LogLevel::LOG_ERROR, "[InvoicesScreen] " + s); }

static bool deserializeJsonSkippingBOM(JsonDocument& doc, File& f) {
    uint8_t bom[3]; size_t n = f.read(bom, 3);
    if (n == 3 && bom[0]==0xEF && bom[1]==0xBB && bom[2]==0xBF) {
        logInfo("BOM detected and skipped");
    } else {
        f.seek(0);
    }
    auto err = deserializeJson(doc, f);
    if (err) { logWarn(String("deserializeJson error: ") + err.c_str()); return false; }
    return true;
}

static String tmToYmd(const struct tm& t) {
    char buf[11];
    snprintf(buf, sizeof(buf), "%04d-%02d-%02d", t.tm_year+1900, t.tm_mon+1, t.tm_mday);
    return String(buf);
}

static bool sumProcessedForPeriod(const struct tm& start, const struct tm& endInclusive, double& outTotal, double& outKwh) {
    outTotal = 0.0; outKwh = 0.0;
    time_t ts = mktime(const_cast<struct tm*>(&start));
    struct tm end = endInclusive; end.tm_hour=23; end.tm_min=59; end.tm_sec=59;
    time_t tsEnd = mktime(&end);
    int days = 0;

    logInfo("Fallback processed: from " + tmToYmd(start) + " to " + tmToYmd(endInclusive));
    for (; ts <= tsEnd; ts += 86400) {
        struct tm d; localtime_r(&ts, &d);
        char path[128];
        snprintf(path, sizeof(path), "%s/%04d-%02d-%02d_processed.json", DAILY_JSON_DIR, d.tm_year+1900, d.tm_mon+1, d.tm_mday);
        if (!SD.exists(path)) { logWarn(String("processed not found: ") + path); continue; }
        File f = SD.open(path, FILE_READ);
        if (!f) { logWarn(String("open fail: ")+path); continue; }
        JsonDocument doc;
        if (!deserializeJsonSkippingBOM(doc, f)) { f.close(); continue; }
        f.close();
        double cost = (double)(doc["cost_eur"] | 0.0);
        double hc = (double)(doc["hc_kwh"] | 0.0);
        double hp = (double)(doc["hp_kwh"] | 0.0);
        outTotal += cost;
        outKwh += (hc + hp);
        days++;
    }
    logInfo("Fallback processed result: days=" + String(days) + " total=" + String(outTotal,2) + " kWh=" + String(outKwh,3));
    return (days > 0) && (outTotal > 0.0 || outKwh > 0.0);
}

static bool readArchiveSummary(const struct tm& start, const struct tm& end, double& outTotal, double& outKwh) {
    char sA[11], sB[11];
    snprintf(sA, sizeof(sA), "%04d-%02d-%02d", start.tm_year + 1900, start.tm_mon + 1, start.tm_mday);
    snprintf(sB, sizeof(sB), "%04d-%02d-%02d", end.tm_year + 1900, end.tm_mon + 1, end.tm_mday);
    char path[160];
    snprintf(path, sizeof(path), "%s/%s_%s_summary.json", ARCHIVE_DIR, sA, sB);

    logInfo(String("Read archive summary: ") + path);
    if (!SD.exists(path)) { logWarn("Archive file does not exist"); return false; }
    File f = SD.open(path, FILE_READ);
    if (!f) { logWarn("Archive open failed"); return false; }
    JsonDocument doc;
    bool ok = deserializeJsonSkippingBOM(doc, f);
    f.close();
    if (!ok) return false;
    outTotal = (double)(doc["total_eur"] | 0.0);
    outKwh   = (double)((doc["hc_kwh"] | 0.0) + (doc["hp_kwh"] | 0.0));
    logInfo("Archive parsed: total=" + String(outTotal,2) + " kWh=" + String(outKwh,3));
    return true;
}

static void logArchiveDirectoryOverview() {
    logInfo("Listing archive dir: " + String(ARCHIVE_DIR));
    File dir = SD.open(ARCHIVE_DIR);
    if (!dir) { logWarn("Open archive dir failed"); return; }
    while (true) {
        File e = dir.openNextFile();
        if (!e) break;
        if (!e.isDirectory()) {
            logInfo(String(" - ") + e.name() + " size=" + String((uint32_t)e.size()));
        }
        e.close();
    }
    dir.close();
}

static void showToast(const char* text) {
    const int w = 220, h = 38;
    const int x = (M5.Display.width()  - w) / 2;
    const int y = (M5.Display.height() - h) / 2;
    M5.Display.fillRoundRect(x, y, w, h, 6, TFT_BLACK);
    M5.Display.drawRoundRect(x, y, w, h, 6, TFT_WHITE);
    M5.Display.setTextColor(TFT_WHITE);
    M5.Display.setTextDatum(textdatum_t::middle_center);
    M5.Display.setFont(&fonts::FreeSansBold12pt7b);
    M5.Display.drawString(text, x + w / 2, y + h / 2);
    s_toastUntil = millis() + 2000;
}

static void getPeriodStartForOffset(int offset, struct tm& periodStart) {
    struct tm nowTm;
    getLocalTime(&nowTm);
    periodStart = nowTm;
    periodStart.tm_hour = 0; periodStart.tm_min = 0; periodStart.tm_sec = 0;
    if (nowTm.tm_mday < g_invoiceParams.billingStartDay) {
        periodStart.tm_mon -= 1;
    }
    periodStart.tm_mday = g_invoiceParams.billingStartDay;
    mktime(&periodStart);
    if (offset != 0) { periodStart.tm_mon += offset; mktime(&periodStart); }
    logInfo("PeriodStart offset=" + String(offset) + " -> " + tmToYmd(periodStart));
}

static const char* monthNameFr(int mon) {
    static const char* months[] = {"Janv","Fevr","Mars","Avril","Mai","Juin","Juil","Aout","Sept","Oct","Nov","Dec"};
    if (mon < 0 || mon > 11) return "---";
    return months[mon];
}
static String periodLabelFr(const struct tm& start, const struct tm& endInclusive) {
    char leftDay[3], rightDay[3];
    snprintf(leftDay,  sizeof(leftDay),  "%d", start.tm_mday);
    snprintf(rightDay, sizeof(rightDay), "%d", endInclusive.tm_mday);
    String l = String(leftDay) + " " + monthNameFr(start.tm_mon);
    String r = String(rightDay) + " " + monthNameFr(endInclusive.tm_mon);
    return l + " au " + r;
}

static void ensureCurrentSnapshot() {
    if (s_currCacheValid) return;
    s_currTotalEUR = g_invoiceData.currentBillingTotal;
    s_currKwh      = g_invoiceData.totalHcKwh + g_invoiceData.totalHpKwh;
    struct tm nowTm; getLocalTime(&nowTm);
    struct tm start; getPeriodStartForOffset(0, start);
    time_t tsNow = mktime(&nowTm);
    time_t tsStart = mktime(&start);
    if ((tsNow - tsStart) >= 86400) {
        s_currTotalEUR = round(s_currTotalEUR * 10.0) / 10.0;
    }
    s_currCacheValid = true;
    logInfo("Snapshot cached: kWh=" + String(s_currKwh,3) + " total=" + String(s_currTotalEUR,2));
}

static void drawScreenContent() {
    std::vector<uint8_t> jpg_buffer;
    if (SD.exists("/elec.jpg")) {
        File f = SD.open("/elec.jpg");
        if (f) {
            jpg_buffer.resize(f.size());
            f.read(jpg_buffer.data(), jpg_buffer.size());
            f.close();
            M5.Display.drawJpg(jpg_buffer.data(), jpg_buffer.size());
        } else {
            M5.Display.fillScreen(TFT_BLACK);
            M5.Display.setTextColor(TFT_WHITE); M5.Display.setCursor(20, 20);
            M5.Display.print("elec.jpg open fail");
        }
    } else {
        M5.Display.fillScreen(TFT_BLACK);
        M5.Display.setTextColor(TFT_WHITE); M5.Display.setCursor(20, 20);
        M5.Display.print("elec.jpg not found");
    }

    M5.Display.setTextDatum(textdatum_t::top_center);
    M5.Display.setTextColor(TFT_BLACK);

    struct tm periodStart;
    getPeriodStartForOffset(s_periodOffset, periodStart);
    struct tm periodEnd = periodStart;
    periodEnd.tm_mon += 1; mktime(&periodEnd);
    time_t periodEndTs = mktime(&periodEnd) - 86400;
    localtime_r(&periodEndTs, &periodEnd);

    String periodStr = periodLabelFr(periodStart, periodEnd);
    double total = 0.0, kwh = 0.0;
    bool dataFound = false;
    logInfo("Draw content for " + tmToYmd(periodStart) + " -> " + tmToYmd(periodEnd));

    if (s_periodOffset == 0) {
        ensureCurrentSnapshot();
        total = s_currTotalEUR;
        kwh   = s_currKwh;
        dataFound = true;
        logInfo("Using cached snapshot: kWh=" + String(kwh,3) + " total=" + String(total,2));
    } else {
        dataFound = readArchiveSummary(periodStart, periodEnd, total, kwh);
        if (!dataFound) {
            logWarn("Archive missing for period, trying fallback processed...");
            dataFound = sumProcessedForPeriod(periodStart, periodEnd, total, kwh);
        }
    }

    if (!dataFound) {
        logWarn("No data for period. Showing toast and reverting offset.");
        showToast("Plus rien !");
        s_periodOffset += 1;
        return;
    }

    M5.Display.setFont(&fonts::FreeSansBold12pt7b);
    M5.Display.drawString(periodStr, 160, 30);
    M5.Display.setFont(&fonts::FreeSansBold18pt7b);
    M5.Display.drawString(String(kwh, 1) + " kWh", 160, 80);
    M5.Display.setFont(&fonts::FreeSansBold24pt7b);
    char euroStr[32]; snprintf(euroStr, sizeof(euroStr), "%.2f EUR", total);
    M5.Display.drawString(euroStr, 160, 120);
}

void show() {
    logInfo("== Enter Invoices screen ==");
    const char* kLogFile = LOG_FILE;
    if (!SD.exists(kLogFile)) {
        File f = SD.open(kLogFile, FILE_WRITE);
        if (f) { f.println(""); f.close(); }
        logInfo("log file created: " + String(kLogFile));
    }
    s_periodOffset = 0;
    s_toastUntil = 0;
    s_currCacheValid = false;
    ensureCurrentSnapshot();
    drawScreenContent();
    logArchiveDirectoryOverview();
}

void loop() {
    if (s_toastUntil > 0 && millis() > s_toastUntil) {
        s_toastUntil = 0;
        drawScreenContent();
    }
    if (M5.BtnA.wasPressed()) {
        logInfo("BtnA previous month");
        s_periodOffset--;
        s_toastUntil = 0;
        drawScreenContent();
        return;
    }
    if (M5.BtnB.wasPressed()) {
        logInfo("BtnB back to dashboard");
        dispState.currentScreen = Screen::SCREEN_DASHBOARD;
        dispState.needsRedraw = true; // MODIFIÉ : Forcer le rafraîchissement de l'écran principal
        return;
    }
    if (M5.BtnC.wasPressed()) {
        logInfo("BtnC next month");
        if (s_periodOffset < 0) {
            s_periodOffset++;
            s_toastUntil = 0;
            drawScreenContent();
        } else {
            logWarn("Already at current period");
        }
        return;
    }
}

} // namespace InvoicesScreen
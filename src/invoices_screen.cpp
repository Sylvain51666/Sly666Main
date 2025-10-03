#include "invoices_screen.h"
#include "state.h"
#include "invoices_store.h"
#include "config.h"
#include "data_logging.h"
#include <ArduinoJson.h>
#include <M5Unified.h>
#include <SD.h>
#include <time.h>

// Déclaration du Mutex SD global
extern SemaphoreHandle_t g_sdMutex;

namespace InvoicesScreen {

// Variables statiques pour l'état de l'écran
static int s_periodOffset = 0;
static unsigned long s_toastUntil = 0;
static bool s_currCacheValid = false;
static double s_currTotalEUR = 0.0;
static double s_currKwh = 0.0;

// Fonctions de log internes
static void logInfo(const String& s){ DataLogging::writeLog(LogLevel::LOG_INFO, "[InvoicesScreen] " + s); }
static void logWarn(const String& s){ DataLogging::writeLog(LogLevel::LOG_WARN, "[InvoicesScreen] " + s); }

// Fonction utilitaire pour lire un JSON en ignorant le BOM (Byte Order Mark)
static bool deserializeJsonSkippingBOM(JsonDocument& doc, File& f) {
    uint8_t bom[3];
    if (f.peek() == 0xEF) { // Vérifie le premier octet du BOM
        f.read(bom, 3);
    }
    auto err = deserializeJson(doc, f);
    if (err) { logWarn(String("deserializeJson error: ") + err.c_str()); return false; }
    return true;
}

// Convertit une structure 'tm' en chaîne "YYYY-MM-DD"
static String tmToYmd(const struct tm& t) {
    char buf[11];
    snprintf(buf, sizeof(buf), "%04d-%02d-%02d", t.tm_year+1900, t.tm_mon+1, t.tm_mday);
    return String(buf);
}

// Fait la somme des fichiers "_processed.json" pour une période donnée (méthode de secours)
static bool sumProcessedForPeriod(const struct tm& start, const struct tm& endInclusive, double& outTotal, double& outKwh) {
    outTotal = 0.0; outKwh = 0.0;
    time_t ts = mktime(const_cast<struct tm*>(&start));
    struct tm end = endInclusive; end.tm_hour=23; end.tm_min=59; end.tm_sec=59;
    time_t tsEnd = mktime(&end);
    int days = 0;
    for (; ts <= tsEnd; ts += 86400) {
        struct tm d; localtime_r(&ts, &d);
        char path[128];
        snprintf(path, sizeof(path), "%s/%04d-%02d-%02d_processed.json", DAILY_JSON_DIR, d.tm_year+1900, d.tm_mon+1, d.tm_mday);

        if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(1000)) == pdTRUE) {
            if (SD.exists(path)) {
                File f = SD.open(path, FILE_READ);
                if (f) {
                    JsonDocument doc;
                    if (deserializeJsonSkippingBOM(doc, f)) {
                        outTotal += (double)(doc["cost_eur"] | 0.0);
                        outKwh += (double)((doc["hc_kwh"] | 0.0) + (doc["hp_kwh"] | 0.0));
                        days++;
                    }
                    f.close();
                }
            }
            xSemaphoreGive(g_sdMutex);
        }
    }
    logInfo("Fallback processed result: days=" + String(days) + " total=" + String(outTotal,2));
    return (days > 0);
}

// Lit le résumé d'une archive mensuelle
static bool readArchiveSummary(const struct tm& start, const struct tm& end, double& outTotal, double& outKwh) {
    char sA[11], sB[11];
    snprintf(sA, sizeof(sA), "%04d-%02d-%02d", start.tm_year + 1900, start.tm_mon + 1, start.tm_mday);
    snprintf(sB, sizeof(sB), "%04d-%02d-%02d", end.tm_year + 1900, end.tm_mon + 1, end.tm_mday);
    char path[160];
    snprintf(path, sizeof(path), "%s/%s_%s_summary.json", ARCHIVE_DIR, sA, sB);
    bool found = false;
    if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(1000)) == pdTRUE) {
        File f = SD.open(path, FILE_READ);
        if (f) {
            JsonDocument doc;
            if (deserializeJsonSkippingBOM(doc, f)) {
                outTotal = (double)(doc["total_eur"] | 0.0);
                outKwh = (double)((doc["hc_kwh"] | 0.0) + (doc["hp_kwh"] | 0.0));
                found = true;
            }
            f.close();
        }
        xSemaphoreGive(g_sdMutex);
    }
    return found;
}

static void showToast(const char* text) {
    const int w = 220, h = 38;
    const int x = (M5.Display.width() - w) / 2;
    const int y = (M5.Display.height() - h) / 2;
    M5.Display.fillRoundRect(x, y, w, h, 6, TFT_BLACK);
    M5.Display.drawRoundRect(x, y, w, h, 6, TFT_WHITE);
    M5.Display.setTextColor(TFT_WHITE);
    M5.Display.setTextDatum(textdatum_t::middle_center);
    M5.Display.setFont(&fonts::FreeSansBold12pt7b);
    M5.Display.drawString(text, x + w / 2, y + h / 2);
    s_toastUntil = millis() + 2000;
}

// Calcule les dates de début et de fin de la période à afficher
static void getPeriodForOffset(int offset, struct tm& periodStart, struct tm& periodEnd) {
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
    periodEnd = periodStart;
    periodEnd.tm_mon += 1;
    mktime(&periodEnd);
    time_t periodEndTs = mktime(&periodEnd) - 86400;
    localtime_r(&periodEndTs, &periodEnd);
}

// Met en cache les totaux de la période actuelle pour un affichage rapide
static void ensureCurrentSnapshot() {
    if (s_currCacheValid) return;
    s_currTotalEUR = g_invoiceData.currentBillingTotal;
    s_currKwh = g_invoiceData.totalHcKwh + g_invoiceData.totalHpKwh;
    s_currCacheValid = true;
    logInfo("Snapshot cached: kWh=" + String(s_currKwh,3) + " total=" + String(s_currTotalEUR,2));
}

// Dessine le contenu de l'écran
static void drawScreenContent() {
    // CORRECTION : Utiliser un objet File au lieu de drawJpgFile
    if (xSemaphoreTake(g_sdMutex, pdMS_TO_TICKS(1000)) == pdTRUE) {
        File jpgFile = SD.open("/elec.jpg", FILE_READ);
        if (jpgFile) {
            // Passer le pointeur vers File au lieu de SD directement
            M5.Display.drawJpg(&jpgFile, jpgFile.size(), 0, 0);
            jpgFile.close();
        } else {
            M5.Display.fillScreen(TFT_BLACK);
            M5.Display.drawString("elec.jpg missing", 160, 120);
        }
        xSemaphoreGive(g_sdMutex);
    } else {
        M5.Display.fillScreen(TFT_BLACK);
        M5.Display.drawString("SD Error", 160, 120);
    }

    M5.Display.setTextDatum(textdatum_t::top_center);
    M5.Display.setTextColor(TFT_BLACK);

    struct tm periodStart, periodEnd;
    getPeriodForOffset(s_periodOffset, periodStart, periodEnd);

    // Formatage du label de la période (ex: "24 Sept au 23 Oct")
    auto monthNameFr = [](int mon) -> const char* {
        static const char* months[] = {"Janv","Fevr","Mars","Avril","Mai","Juin","Juil","Aout","Sept","Oct","Nov","Dec"};
        return (mon >= 0 && mon < 12) ? months[mon] : "---";
    };
    String periodStr = String(periodStart.tm_mday) + " " + monthNameFr(periodStart.tm_mon) + " au " +
                       String(periodEnd.tm_mday) + " " + monthNameFr(periodEnd.tm_mon);

    double total = 0.0, kwh = 0.0;
    bool dataFound = false;
    if (s_periodOffset == 0) { // Période actuelle
        ensureCurrentSnapshot();
        total = s_currTotalEUR;
        kwh = s_currKwh;
        dataFound = (total > 0 || kwh > 0);
    } else { // Périodes passées (archives)
        dataFound = readArchiveSummary(periodStart, periodEnd, total, kwh);
        if (!dataFound) {
            // Si pas d'archive, on essaie de recalculer depuis les fichiers journaliers
            dataFound = sumProcessedForPeriod(periodStart, periodEnd, total, kwh);
        }
    }
    if (!dataFound) {
        showToast("Plus rien !");
        s_periodOffset++; // On revient à la période précédente
        return;
    }

    M5.Display.setFont(&fonts::FreeSansBold12pt7b);
    M5.Display.drawString(periodStr, 160, 30);
    M5.Display.setFont(&fonts::FreeSansBold18pt7b);
    M5.Display.drawString(String(kwh, 1) + " kWh", 160, 80);
    M5.Display.setFont(&fonts::FreeSansBold24pt7b);
    char euroStr[32];
    snprintf(euroStr, sizeof(euroStr), "%.2f EUR", total);
    M5.Display.drawString(euroStr, 160, 120);
}

// --- Fonctions publiques ---

void show() {
    logInfo("== Enter Invoices screen ==");
    s_periodOffset = 0;
    s_toastUntil = 0;
    s_currCacheValid = false; // Forcer la mise à jour du cache
    drawScreenContent();
}

void loop() {
    if (s_toastUntil > 0 && millis() > s_toastUntil) {
        s_toastUntil = 0;
        drawScreenContent();
    }

    if (M5.BtnA.wasPressed()) {
        logInfo("BtnA: previous month");
        s_periodOffset--;
        s_toastUntil = 0;
        drawScreenContent();
    }

    if (M5.BtnB.wasPressed()) {
        logInfo("BtnB: back to dashboard");
        dispState.currentScreen = Screen::SCREEN_DASHBOARD;
        dispState.needsRedraw = true;
    }

    if (M5.BtnC.wasPressed()) {
        if (s_periodOffset < 0) {
            logInfo("BtnC: next month");
            s_periodOffset++;
            s_toastUntil = 0;
            drawScreenContent();
        }
    }
}

} // namespace InvoicesScreen

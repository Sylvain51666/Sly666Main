// src/boot_ui.cpp
#include "boot_ui.h"
#include <M5Unified.h>
#include <math.h>

namespace BootUI {

// --- Variables d'état internes au module ---
static int current_percent = -1;
static String current_label = "";
static unsigned long last_spinner_update = 0;
static unsigned long last_pulse_update = 0;
static int spinner_step = 0;
static float pulse_phase = 0.0f;
static bool s_active = false;

// --- Constantes de mise en page ---
constexpr int BAR_WIDTH = 240;
constexpr int BAR_HEIGHT = 16;
constexpr int BAR_X = (320 - BAR_WIDTH) / 2;
constexpr int BAR_Y = 130;
constexpr int SPINNER_RADIUS = 16;
constexpr int SPINNER_Y = BAR_Y - 50;
constexpr int PULSE_CIRCLE_Y = SPINNER_Y - 5;
constexpr int PULSE_MAX_RADIUS = 22;

// Dégradé moderne bleu-cyan pour la barre
static void drawProgressBar(int percent) {
    if (percent < 0) percent = 0;
    if (percent > 100) percent = 100;
    if (percent == current_percent) return;

    int old_fill_width = (current_percent * BAR_WIDTH) / 100;
    int new_fill_width = (percent * BAR_WIDTH) / 100;

    if (new_fill_width < old_fill_width) {
        old_fill_width = 0;
        M5.Display.fillRoundRect(BAR_X, BAR_Y, BAR_WIDTH, BAR_HEIGHT, 4, TFT_BLACK);
        M5.Display.drawRoundRect(BAR_X, BAR_Y, BAR_WIDTH, BAR_HEIGHT, 4, TFT_DARKGREY);
    }

    // Dégradé cyan progressif
    uint8_t r_start = 0, g_start = 80, b_start = 120;
    uint8_t r_end = 0, g_end = 200, b_end = 255;

    for (int x = old_fill_width; x < new_fill_width; ++x) {
        float ratio = (float)x / (float)BAR_WIDTH;
        uint8_t r = r_start + (r_end - r_start) * ratio;
        uint8_t g = g_start + (g_end - g_start) * ratio;
        uint8_t b = b_start + (b_end - b_start) * ratio;
        M5.Display.drawFastVLine(BAR_X + x + 1, BAR_Y + 2, BAR_HEIGHT - 4, M5.Display.color565(r, g, b));
    }

    current_percent = percent;
}

static void drawPercentText(int percent) {
    M5.Display.setTextFont(4);
    M5.Display.setTextDatum(top_center);
    M5.Display.setTextColor(TFT_CYAN, TFT_BLACK);
    M5.Display.fillRect(BAR_X, BAR_Y + BAR_HEIGHT + 8, BAR_WIDTH, 30, TFT_BLACK);
    M5.Display.drawString(String(percent) + "%", 160, BAR_Y + BAR_HEIGHT + 8);
}

static void drawLabel(const String& label) {
    current_label = label;
    M5.Display.setTextFont(2);
    M5.Display.setTextDatum(top_center);
    M5.Display.setTextColor(TFT_LIGHTGREY, TFT_BLACK);
    M5.Display.fillRect(0, BAR_Y + BAR_HEIGHT + 45, 320, 22, TFT_BLACK);
    M5.Display.drawString(current_label, 160, BAR_Y + BAR_HEIGHT + 45);
}

// Animation spinner orbital moderne
static void drawSpinner() {
    const int num_dots = 8;
    const int orbit_radius = SPINNER_RADIUS;
    
    for (int i = 0; i < num_dots; ++i) {
        float angle = (float)i * (2.0f * PI / num_dots) + (spinner_step * 0.15f);
        int x = 160 + orbit_radius * cos(angle);
        int y = SPINNER_Y + orbit_radius * sin(angle);
        
        // Taille variable selon position dans l'orbite
        int size = 3 + (i == (spinner_step % num_dots) ? 2 : 0);
        
        // Couleur dégradée cyan
        int brightness = 100 + (155 * i / num_dots);
        uint16_t color = M5.Display.color565(0, brightness * 0.8, brightness);
        M5.Display.fillCircle(x, y, size, color);
    }
}

// Animation pulse autour du spinner
static void drawPulseAnimation() {
    int radius = (int)(PULSE_MAX_RADIUS * (0.5f + 0.5f * sin(pulse_phase)));
    int alpha = (int)(80 * (1.0f - (radius / (float)PULSE_MAX_RADIUS)));
    
    if (alpha > 10) {
        uint16_t color = M5.Display.color565(0, 150, 255);
        M5.Display.drawCircle(160, PULSE_CIRCLE_Y, radius, color);
        if (radius > 2) {
            M5.Display.drawCircle(160, PULSE_CIRCLE_Y, radius - 1, color);
        }
    }
}

void begin() {
    M5.Display.fillScreen(TFT_BLACK);
    M5.Display.setTextFont(4);
    M5.Display.setTextDatum(top_center);
    M5.Display.setTextColor(TFT_CYAN, TFT_BLACK);
    M5.Display.drawString("M5Core2 Boot", 160, 30);
    
    M5.Display.drawRoundRect(BAR_X, BAR_Y, BAR_WIDTH, BAR_HEIGHT, 4, TFT_DARKGREY);
    setProgress(0, "Initialisation...");
    s_active = true;
}

void setProgress(int percent, const String& label) {
    drawProgressBar(percent);
    drawPercentText(percent);
    drawLabel(label);
}

void setStep(int stepIndex, int stepCount, const String& label) {
    if (stepCount == 0) return;
    int percent = (stepIndex * 100) / stepCount;
    setProgress(percent, label);
}

void loop() {
    unsigned long now = millis();
    
    // Mise à jour spinner toutes les 60ms
    if (now - last_spinner_update > 60) {
        // Effacer zone spinner
        M5.Display.fillCircle(160, SPINNER_Y, SPINNER_RADIUS + 6, TFT_BLACK);
        M5.Display.fillCircle(160, PULSE_CIRCLE_Y, PULSE_MAX_RADIUS + 2, TFT_BLACK);
        
        drawPulseAnimation();
        drawSpinner();
        
        spinner_step++;
        last_spinner_update = now;
    }
    
    // Mise à jour pulse toutes les 30ms
    if (now - last_pulse_update > 30) {
        pulse_phase += 0.15f;
        if (pulse_phase > 2 * PI) pulse_phase -= 2 * PI;
        last_pulse_update = now;
    }
}

void drawVersion(const char* version) {
    M5.Display.setTextFont(2);
    M5.Display.setTextDatum(top_left);
    M5.Display.setTextColor(TFT_DARKGREY, TFT_BLACK);
    M5.Display.drawString(version, 5, 5);
}

bool isActive() { return s_active; }
void setActive(bool active) { s_active = active; }

} // namespace BootUI

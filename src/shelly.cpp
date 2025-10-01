//==================================================
// Fichier : src2/shelly.cpp
//==================================================
#include "shelly.h"
#include "secrets.h"
#include "config.h"
#include "state.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

// Shared with main.cpp
extern volatile float g_rawMaisonW, g_rawPVW;
extern volatile bool g_hasNewShelly;
extern portMUX_TYPE g_shellyMux;
extern TaskHandle_t g_shellyTaskHandle;

void shellyTask(void* arg) {
    uint32_t lastPoll = 0;
    
    for (;;) {
        if (!WiFi.isConnected()) {
            vTaskDelay(pdMS_TO_TICKS(500));
            continue;
        }

        if (millis() - lastPoll < SHELLY_POLL_MS) {
            vTaskDelay(pdMS_TO_TICKS(20));
            continue;
        }

        lastPoll = millis();
        HTTPClient http;
        String url = String("http://") + SHELLY_HOST + "/rpc/Shelly.GetStatus";
        
        if (!http.begin(url)) {
            vTaskDelay(pdMS_TO_TICKS(200));
            continue;
        }

        // OPTIMISATION: Timeout réduit de 700ms à 400ms pour réduire les blocages
        http.setTimeout(400);
        int code = http.GET();
        
        if (code == 200) {
            JsonDocument filter;
            filter["em1:0"]["act_power"] = true;
            filter["em1:1"]["act_power"] = true;
            JsonDocument doc;
            
            if (deserializeJson(doc, http.getStream(), DeserializationOption::Filter(filter)) == DeserializationError::Ok) {
                float m = doc["em1:0"]["act_power"] | NAN;
                float p = doc["em1:1"]["act_power"] | NAN;
                
                portENTER_CRITICAL(&g_shellyMux);
                g_rawMaisonW = m;
                g_rawPVW = p;
                g_hasNewShelly = true;
                portEXIT_CRITICAL(&g_shellyMux);
            }
        }
        
        http.end();
        vTaskDelay(pdMS_TO_TICKS(10));
    }
}

namespace Shelly {

void startTask() {
    xTaskCreatePinnedToCore(
        shellyTask,
        "ShellyPoll",
        8192,
        nullptr,
        1,
        &g_shellyTaskHandle,
        0  // Core 0 pour Shelly
    );
}

} // namespace Shelly

#include "shelly.h"
#include "secrets.h"
#include "config.h"
#include "state.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

// Variables globales partagées avec main.cpp
extern volatile float g_rawMaisonW, g_rawPVW;
extern volatile bool g_hasNewShelly;
extern portMUX_TYPE g_shellyMux;
extern TaskHandle_t g_shellyTaskHandle;

// La tâche FreeRTOS qui tourne en continu sur un cœur dédié.
void shellyTask(void* arg) {
    uint32_t lastPoll = 0;
    
    for (;;) { // Boucle infinie
        // On attend que le WiFi soit connecté
        if (!WiFi.isConnected()) {
            vTaskDelay(pdMS_TO_TICKS(1000)); // Pause d'une seconde si pas de WiFi
            continue;
        }

        // On respecte l'intervalle de polling défini dans config.h
        if (millis() - lastPoll < SHELLY_POLL_MS) {
            vTaskDelay(pdMS_TO_TICKS(50));
            continue;
        }

        lastPoll = millis();
        HTTPClient http;
        String url = String("http://") + SHELLY_HOST + "/rpc/Shelly.GetStatus";
        
        if (!http.begin(url)) {
            vTaskDelay(pdMS_TO_TICKS(200));
            continue;
        }

        http.setTimeout(400); // Timeout court pour ne pas bloquer si le Shelly ne répond pas
        int code = http.GET();
        
        if (code == 200) {
            // On filtre le JSON pour ne parser que les données qui nous intéressent
            JsonDocument filter;
            filter["em1:0"]["act_power"] = true;
            filter["em1:1"]["act_power"] = true;
            JsonDocument doc;
            
            if (deserializeJson(doc, http.getStream(), DeserializationOption::Filter(filter)) == DeserializationError::Ok) {
                float m = doc["em1:0"]["act_power"] | NAN; // Puissance Maison
                float p = doc["em1:1"]["act_power"] | NAN; // Puissance PV
                
                // On utilise une section critique pour mettre à jour les variables partagées
                // C'est la méthode la plus sûre pour communiquer entre les tâches.
                portENTER_CRITICAL(&g_shellyMux);
                g_rawMaisonW = m;
                g_rawPVW = p;
                g_hasNewShelly = true; // On lève le drapeau pour la boucle principale
                portEXIT_CRITICAL(&g_shellyMux);
            }
        }
        
        http.end();
        vTaskDelay(pdMS_TO_TICKS(10)); // Petite pause pour laisser du temps aux autres tâches
    }
}

namespace Shelly {

void startTask() {
    // On crée la tâche et on l'épingle au Core 0, qui est optimisé pour les communications réseau.
    xTaskCreatePinnedToCore(
        shellyTask,
        "ShellyPoll",
        4096, // Taille de la stack
        nullptr,
        2,    // Priorité
        &g_shellyTaskHandle,
        0     // Core 0
    );
}

} // namespace Shelly
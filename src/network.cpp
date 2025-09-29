#include "network.h"
#include "secrets.h"
#include "config.h"
#include "state.h"
#include "data_logging.h"
#include "utils.h"
#include "boot_ui.h"
#include "invoices_store.h"
#include "settings.h"
#include <WiFi.h>
#include <PubSubClient.h>

static WiFiClient wifiClient;

static void mqttCallback(char* topic, byte* payload, unsigned int length);
static void handlePoolTopics(const char* payloadStr);
static void handleWaterTopics(const char* topic, const char* payloadStr);
static void handleTimeAndPumpSchedule(const char* topic, const char* payloadStr);
static void handleSolarTopics(const char* topic, const char* payloadStr);
static void handleHumidityTopic(const char* payloadStr);

namespace Network {
    PubSubClient mqttClient(wifiClient);
    static unsigned long lastMqttReconnectAttempt = 0;
    static unsigned long lastWifiReconnectAttempt = 0;

    void init() {
        WiFi.mode(WIFI_STA);
        WiFi.setSleep(false);
        WiFi.begin(WIFI_SSID, WIFI_PASS);
        
        unsigned long start = millis();
        while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
            delay(250);
            yield();
        }
        
        mqttClient.setServer(MQTT_BROKER, 1883);
        mqttClient.setKeepAlive(60);
        mqttClient.setCallback(mqttCallback);
    }

    void connectWifi() {
        if (WiFi.isConnected() || millis() - lastWifiReconnectAttempt < WIFI_RECONNECT_INTERVAL_MS) {
            return;
        }
        lastWifiReconnectAttempt = millis();
        sysState.wifiReconnectAttempts++;
        
        char logMsg[128];
        snprintf(logMsg, sizeof(logMsg), "Reconnecting to WiFi: %s (attempt %d)", WIFI_SSID, sysState.wifiReconnectAttempts);
        DataLogging::writeLog(LogLevel::LOG_INFO, logMsg);
        
        WiFi.disconnect();
        WiFi.begin(WIFI_SSID, WIFI_PASS);
    }

    void connectMqtt() {
        if (mqttClient.connected() || !WiFi.isConnected()) {
            if (mqttClient.connected()) sysState.mqttReconnectAttempts = 0;
            return;
        }
        if (millis() - lastMqttReconnectAttempt < MQTT_RECONNECT_INTERVAL_MS) return;

        lastMqttReconnectAttempt = millis();
        sysState.mqttReconnectAttempts++;
        
        char logMsg[80];
        snprintf(logMsg, sizeof(logMsg), "Connecting to MQTT (attempt %d)", sysState.mqttReconnectAttempts);
        DataLogging::writeLog(LogLevel::LOG_INFO, logMsg);
        
        if (mqttClient.connect(MQTT_CLIENT_ID, MQTT_USER, MQTT_PASS)) {
            DataLogging::writeLog(LogLevel::LOG_INFO, "MQTT connected");
            
            mqttClient.subscribe(SETTINGS.topic_piscine.c_str());
            mqttClient.subscribe(SETTINGS.topic_eau.c_str());
            mqttClient.subscribe(SETTINGS.topic_heure.c_str());
            mqttClient.subscribe(SETTINGS.topic_pompe_piscine.c_str());
            mqttClient.subscribe(SETTINGS.topic_pompe_hr_start.c_str());
            mqttClient.subscribe(SETTINGS.topic_pompe_hr_end.c_str());
            mqttClient.subscribe(SETTINGS.topic_autoconsommation.c_str());
            mqttClient.subscribe(SETTINGS.topic_prod_totale.c_str());
            mqttClient.subscribe(SETTINGS.topic_temp_onduleur_1.c_str());
            mqttClient.subscribe(SETTINGS.topic_temp_onduleur_2.c_str());
            mqttClient.subscribe(SETTINGS.topic_talon_eau.c_str());
            mqttClient.subscribe(SETTINGS.topic_studio_humidity.c_str());
            mqttClient.subscribe(SETTINGS.topic_sub_price.c_str());
            mqttClient.subscribe(SETTINGS.topic_hc_price.c_str());
            mqttClient.subscribe(SETTINGS.topic_bill_day.c_str());
            mqttClient.subscribe(SETTINGS.topic_hp_price.c_str());

            sysState.lastDataReceived = millis();
        } else {
            DataLogging::writeLog(LogLevel::LOG_ERROR, "MQTT connection failed, rc=" + String(mqttClient.state()) + ".");
            int backoff = min((int)60000, (int)(MQTT_RECONNECT_INTERVAL_MS * (1 << min(sysState.mqttReconnectAttempts - 1, 4))));
            lastMqttReconnectAttempt += backoff - MQTT_RECONNECT_INTERVAL_MS;
        }
    }

    void loop() {
        if (!WiFi.isConnected()) {
            connectWifi();
        } else {
            connectMqtt();
            if (mqttClient.connected()) {
                mqttClient.loop();
            }
        }
    }

} // namespace Network


// VERSION CORRIGÉE : Remplacement complet de la fonction pour une meilleure robustesse
static void handlePoolTopics(const char* payloadCStr) {
    String payloadStr(payloadCStr);
    DataLogging::writeLog(LogLevel::LOG_DEBUG, "[Network] handlePoolTopics received payload: '" + payloadStr + "'");

    // Extraction de la température de la piscine (tout ce qui est avant le '/')
    int slashIndex = payloadStr.indexOf('/');
    String tempPiscineStr = (slashIndex != -1) ? payloadStr.substring(0, slashIndex) : payloadStr;
    float tempPiscine = tempPiscineStr.toFloat();
    
    if (Utils::isValidTemperature(tempPiscine, "piscine")) {
        sensorData.piscine_temp = Utils::formatTemperature(tempPiscine);
    }

    // Extraction de la valeur de la PAC (tout ce qui est après le '/')
    sensorData.pac_value_float = NAN; // Reset
    if (slashIndex != -1) {
        String pacValStr = payloadStr.substring(slashIndex + 1);
        float pac_val = pacValStr.toFloat();

        if (Utils::isValidTemperature(pac_val, "pac")) {
            sensorData.pac_value_float = pac_val;
            sensorData.pac_temp = Utils::formatTemperature(pac_val);
            DataLogging::writeLog(LogLevel::LOG_DEBUG, "[Network] Stored pac_value_float: " + String(sensorData.pac_value_float, 2));
        } else {
             DataLogging::writeLog(LogLevel::LOG_WARN, "[Network] PAC value not stored: isValidTemperature check failed for '" + pacValStr + "'");
        }
    } else {
        DataLogging::writeLog(LogLevel::LOG_WARN, "[Network] PAC value not stored: separator '/' not found in payload.");
    }
}


static void handleWaterTopics(const char* topic, const char* payloadStr) {
    if (String(topic) == SETTINGS.topic_eau) {
        int litres = atoi(payloadStr);
        sensorData.eau_litres = (litres > 0) ? String(litres) + "L" : "...";
    } else if (String(topic) == SETTINGS.topic_talon_eau) {
        sensorData.talon_water = String(payloadStr) + " L/h";
    }
}
static void handleTimeAndPumpSchedule(const char* topic, const char* payloadStr) {
    String topicStr(topic);
    if (topicStr == SETTINGS.topic_heure) {
        sensorData.heure = payloadStr;
    } else if (topicStr == SETTINGS.topic_pompe_hr_start) {
        if (strlen(payloadStr) >= 5) sensorData.pompe_start = String(payloadStr).substring(0, 5);
    } else if (topicStr == SETTINGS.topic_pompe_hr_end) {
        if (strlen(payloadStr) >= 5) sensorData.pompe_end = String(payloadStr).substring(0, 5);
    }
}
static void handleSolarTopics(const char* topic, const char* payloadStr) {
    String topicStr(topic);
    if (topicStr == SETTINGS.topic_autoconsommation) {
        powerData.autoconsommation = payloadStr;
    } else if (topicStr == SETTINGS.topic_prod_totale) {
        powerData.prod_totale = String(round(atof(payloadStr)));
    } else if (topicStr == SETTINGS.topic_temp_onduleur_1) {
        sensorData.temp_onduleur1 = atof(payloadStr);
    } else if (topicStr == SETTINGS.topic_temp_onduleur_2) {
        sensorData.temp_onduleur2 = atof(payloadStr);
    }
}
static void handleHumidityTopic(const char* payloadStr) {
    float h = atof(payloadStr);
    if (h >= 0.0f && h <= 100.0f) {
        sensorData.studioHumidity = h;
    }
}
static void mqttCallback(char* topic, byte* payload, unsigned int length) {
    sysState.lastDataReceived = millis();
    char payloadStr[length + 1];
    memcpy(payloadStr, payload, length);
    payloadStr[length] = '\0';
    String topicStr = String(topic);
    
    if (topicStr == SETTINGS.topic_sub_price || topicStr == SETTINGS.topic_hc_price || topicStr == SETTINGS.topic_bill_day || topicStr == SETTINGS.topic_hp_price) {
        InvoicesStore::handleMqttMessage(topicStr, String(payloadStr));
    }
    else if (topicStr == SETTINGS.topic_piscine) { handlePoolTopics(payloadStr); } 
    else if (topicStr == SETTINGS.topic_eau || topicStr == SETTINGS.topic_talon_eau) { handleWaterTopics(topic, payloadStr); }
    else if (topicStr == SETTINGS.topic_heure || topicStr == SETTINGS.topic_pompe_hr_start || topicStr == SETTINGS.topic_pompe_hr_end) { handleTimeAndPumpSchedule(topic, payloadStr); }
    else if (topicStr == SETTINGS.topic_pompe_piscine) {
        bool newS = (strcasecmp(payloadStr, "true") == 0 || strcmp(payloadStr, "1") == 0 || strcasecmp(payloadStr, "on") == 0);
        if (newS != sensorData.isPumpRunning) {
            sensorData.isPumpRunning = newS;
            DataLogging::writeLog(LogLevel::LOG_INFO, String("Pompe piscine: ") + (sensorData.isPumpRunning ? "ON" : "OFF"));
        }
    }
    else if (topicStr == SETTINGS.topic_autoconsommation || topicStr == SETTINGS.topic_prod_totale || topicStr == SETTINGS.topic_temp_onduleur_1 || topicStr == SETTINGS.topic_temp_onduleur_2) { 
        handleSolarTopics(topic, payloadStr); 
    }
    else if (topicStr == SETTINGS.topic_studio_humidity) { handleHumidityTopic(payloadStr); }
}
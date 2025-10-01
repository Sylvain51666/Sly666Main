#include "web_server.h"
#include "state.h"
#include "data_logging.h"
#include "invoices_debug.h"
#include "network.h"
#include "utils.h"
#include <WiFi.h>

WebConfigServer WebServerInstance;

void generateDebugJson(JsonDocument& doc) {
    JsonObject system = doc["system"].to<JsonObject>();
    system["uptime_min"] = (millis() - sysState.bootTime) / 60000;
    system["free_ram_kb"] = ESP.getFreeHeap() / 1024;
    system["brightness"] = M5.Display.getBrightness();
    
    if (g_solarTimes.isValid) {
        char srBuf[6], ssBuf[6];
        snprintf(srBuf, sizeof(srBuf), "%d:%02d", g_solarTimes.sunriseHour, g_solarTimes.sunriseMinute);
        snprintf(ssBuf, sizeof(ssBuf), "%d:%02d", g_solarTimes.sunsetHour, g_solarTimes.sunsetMinute);
        system["sunrise"] = srBuf;
        system["sunset"] = ssBuf;
    }

    JsonObject network = doc["network"].to<JsonObject>();
    network["wifi_connected"] = WiFi.isConnected();
    network["wifi_rssi_dbm"] = WiFi.RSSI();
    network["ip_address"] = WiFi.localIP().toString();
    network["mqtt_connected"] = Network::mqttClient.connected();
    network["last_data_sec_ago"] = (millis() - sysState.lastDataReceived) / 1000;

    JsonObject power = doc["power"].to<JsonObject>();
    power["maison_w"] = powerData.maison_watts;
    power["pv_w"] = powerData.pv_watts;
    power["grid_w"] = powerData.grid_watts;
    power["talon_power"] = powerData.talon_power;
    
    int autoconsommation_pct_val = -1;
    if (sscanf(powerData.autoconsommation.c_str(), "%d %%", &autoconsommation_pct_val) == 1) {
        if (autoconsommation_pct_val >= 0 && autoconsommation_pct_val <= 100) {
            power["autoconsommation_pct"] = autoconsommation_pct_val;
        }
    }
    power["autoconsommation_str"] = powerData.autoconsommation;
    
    if (g_powerStats.sampleCount > 0) {
        power["maison_min_w"] = String(g_powerStats.minPower, 0);
        power["maison_max_w"] = String(g_powerStats.maxPower, 0);
        power["maison_avg_w"] = String(g_powerStats.avgPower, 0);
    }

    JsonObject water = doc["water"].to<JsonObject>();
    water["current_litres"] = sensorData.eau_litres;
    water["talon_water"] = sensorData.talon_water;
    if(g_waterStats.dataLoaded) {
        water["yesterday_l"] = g_waterStats.yesterday;
        water["avg_7d_l"] = g_waterStats.avg7d;
        water["avg_30d_l"] = g_waterStats.avg30d;
    }

    JsonObject weather = doc["weather"].to<JsonObject>();
    if(g_weather.valid) {
        weather["current_wind_kmh"] = String(g_weather.currentWindKmh, 1);
        weather["max_wind_today_kmh"] = String(g_weather.maxWindTodayKmh, 1);
        weather["max_gust_today_kmh"] = String(g_weather.maxGustTodayKmh, 1);
    }
    weather["last_http_code"] = g_weather.lastHttpCode;

    JsonObject inverter = doc["inverter"].to<JsonObject>();
    inverter["temp1_c"] = sensorData.temp_onduleur1;
    inverter["temp2_c"] = sensorData.temp_onduleur2;

    String invoiceReport;
    InvoicesDebug::generate_report(invoiceReport);
    doc["invoices_report"] = invoiceReport;
}

WebConfigServer::WebConfigServer(uint16_t port) : _server(port) {}

bool WebConfigServer::begin(const char* hostname) {
    if (!_spiffsBegun) {
        _spiffsBegun = SPIFFS.begin(true);
        if (!_spiffsBegun) {
            Serial.println(F("[WEB] Erreur initialisation SPIFFS."));
            return false;
        }
    }

    _server.serveStatic("/", SPIFFS, "/").setDefaultFile("index.html").setCacheControl("max-age=3600");
    
    _server.on("/api/settings", HTTP_GET, std::bind(&WebConfigServer::handleGetSettings, this, std::placeholders::_1));
    
    _server.on(
        "/api/settings", HTTP_POST, [](AsyncWebServerRequest* req){}, nullptr,
        std::bind(&WebConfigServer::handlePostSettings, this, std::placeholders::_1, std::placeholders::_2, std::placeholders::_3, std::placeholders::_4, std::placeholders::_5)
    );
    
    _server.on("/api/settings", HTTP_OPTIONS, [](AsyncWebServerRequest* req){
        AsyncWebServerResponse* res = req->beginResponse(204);
        res->addHeader("Access-Control-Allow-Origin", "*");
        res->addHeader("Access-Control-Allow-Methods", "GET,POST,OPTIONS");
        res->addHeader("Access-Control-Allow-Headers", "Content-Type");
        req->send(res);
    });

    _server.on("/api/debug", HTTP_GET, [](AsyncWebServerRequest* req){
        JsonDocument doc;
        DataLogging::loadWaterData();
        generateDebugJson(doc);
        String body;
        serializeJson(doc, body);
        AsyncWebServerResponse* res = req->beginResponse(200, "application/json", body);
        res->addHeader("Cache-Control", "no-store");
        res->addHeader("Access-control-Allow-Origin", "*");
        req->send(res);
    });

    _server.on("/api/reboot", HTTP_GET, std::bind(&WebConfigServer::handleReboot, this, std::placeholders::_1));

    _server.onNotFound([](AsyncWebServerRequest* request) {
        request->send(404, "text/plain", "Not found");
    });

    _server.begin();
    _running = true;
    return true;
}

bool WebConfigServer::isRunning() const { return _running; }

void WebConfigServer::handleGetSettings(AsyncWebServerRequest* req) {
    JsonDocument doc;
    Settings.toJson(doc);
    String body;
    serializeJson(doc, body);
    AsyncWebServerResponse* res = req->beginResponse(200, "application/json", body);
    res->addHeader("Cache-Control", "no-store");
    res->addHeader("Access-Control-Allow-Origin", "*");
    req->send(res);
}

// CORRECTION MAJEURE : Sauvegarde synchrone pour éviter les corruptions
void WebConfigServer::handlePostSettings(AsyncWebServerRequest* req, uint8_t* data, size_t len, size_t index, size_t total) {
    static String body;
    
    if (index == 0) body = "";
    body.concat((const char*)data, len);
    
    if (index + len == total) {
        JsonDocument patch;
        if (deserializeJson(patch, body) != DeserializationError::Ok) {
            req->send(400, "application/json", "{\"error\":\"invalid_json\"}");
            return;
        }

        // Mise à jour en mémoire ET sauvegarde immédiate de manière synchrone
        DataLogging::writeLog(LogLevel::LOG_INFO, "[WebServer] Updating settings from web interface");
        Settings.updateFromJson(patch, true);  // true = save immédiatement
        DataLogging::writeLog(LogLevel::LOG_INFO, "[WebServer] Settings saved successfully");
        
        // Petit délai pour s'assurer que la SD a fini d'écrire
        delay(100);
        
        handleGetSettings(req);
    }
}

void WebConfigServer::handleReboot(AsyncWebServerRequest* req) {
    req->send(200, "application/json", "{\"status\":\"rebooting\"}");
    xTaskCreate([](void*){
        vTaskDelay(pdMS_TO_TICKS(1000));
        ESP.restart();
    }, "rebootTask", 2048, nullptr, 1, nullptr);
}

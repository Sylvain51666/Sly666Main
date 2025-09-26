#include "web_server.h"
#include "state.h"
#include "network.h"
#include "utils.h"
#include <WiFi.h>

WebConfigServer WebServerInstance;

// Fonction pour générer les données de débogage en JSON
void generateDebugJson(JsonDocument& doc) {
    JsonObject system = doc["system"].to<JsonObject>();
    system["uptime_min"] = (millis() - sysState.bootTime) / 60000;
    system["free_ram_kb"] = ESP.getFreeHeap() / 1024;
    system["brightness"] = M5.Display.getBrightness();

    JsonObject network = doc["network"].to<JsonObject>();
    network["wifi_connected"] = WiFi.isConnected();
    network["wifi_rssi"] = WiFi.RSSI();
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
      generateDebugJson(doc);
      String body;
      serializeJson(doc, body);
      AsyncWebServerResponse* res = req->beginResponse(200, "application/json", body);
      res->addHeader("Cache-Control", "no-store");
      res->addHeader("Access-Control-Allow-Origin", "*");
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
    Settings.updateFromJson(patch, true);
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
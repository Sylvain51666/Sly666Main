#pragma once
#include <Arduino.h>
#include <FS.h>
#include <SPIFFS.h>
#include <WiFi.h>
#include <ESPAsyncWebServer.h>
#include <ArduinoJson.h>
#include "settings.h"

class WebConfigServer {
public:
  explicit WebConfigServer(uint16_t port=80);

  bool begin(const char* hostname="m5core2");
  bool isRunning() const;

private:
  AsyncWebServer _server;
  bool _running = false;
  bool _spiffsBegun = false;

  void handleGetSettings(AsyncWebServerRequest* req);
  void handlePostSettings(AsyncWebServerRequest* req, uint8_t* data, size_t len, size_t index, size_t total);
  void handleReboot(AsyncWebServerRequest* req);
};

extern WebConfigServer WebServerInstance;
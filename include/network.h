#pragma once
#include <PubSubClient.h>

namespace Network {

void init();
void loop();
void connectWifi();
void connectMqtt();

// Déclarations pour un accès global
extern PubSubClient mqttClient;
extern int mqttReconnectAttempts;
extern int wifiReconnectAttempts;

} // namespace Network
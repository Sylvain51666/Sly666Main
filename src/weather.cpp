#include "weather.h"
#include "config.h"
#include "state.h"
#include "settings.h"
#include "data_logging.h"
#include "ui.h"
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

// Fonction interne pour la récupération des données
static bool weatherFetchOnce(WeatherData& out) {
    if (WiFi.status() != WL_CONNECTED) {
        out.lastHttpCode = -1001; // Code personnalisé pour "pas de WiFi"
        DataLogging::writeLog(LogLevel::LOG_WARN, "Weather: Cannot fetch, WiFi not connected.");
        return false;
    }

    // Le client est créé localement pour libérer la mémoire après chaque utilisation
    WiFiClientSecure client;
    HTTPClient http;
    
    DataLogging::writeLog(LogLevel::LOG_INFO, "Weather: GET " + SETTINGS.weather_url);
    
    client.setInsecure(); // Nécessaire pour les certificats auto-signés ou non vérifiés
    http.begin(client, SETTINGS.weather_url);
    http.addHeader("User-Agent", "M5Core2-Dashboard/2.0");
    http.setTimeout(10000); // Timeout de 10 secondes

    int code = http.GET();
    out.lastHttpCode = code;
    if (code != 200) {
        DataLogging::writeLog(LogLevel::LOG_ERROR, "Weather: HTTP Error " + String(code) + " " + http.errorToString(code));
        http.end();
        return false;
    }

    String payload = http.getString();
    http.end();

    JsonDocument doc;
    DeserializationError err = deserializeJson(doc, payload);
    if (err) {
        DataLogging::writeLog(LogLevel::LOG_ERROR, "Weather: JSON parsing failed: " + String(err.c_str()));
        return false;
    }

    out.currentWindKmh = doc["current_weather"]["windspeed"] | NAN;
    out.maxWindTodayKmh = doc["daily"]["windspeed_10m_max"][0] | NAN;
    out.maxGustTodayKmh = doc["daily"]["windgusts_10m_max"][0] | NAN;
    out.lastUpdateMs = millis();
    out.valid = !(isnan(out.currentWindKmh) || isnan(out.maxWindTodayKmh) || isnan(out.maxGustTodayKmh));
    
    if (out.valid) {
        DataLogging::writeLog(LogLevel::LOG_INFO, "Weather: OK. Current=" + String(out.currentWindKmh, 1) + ", Max=" + String(out.maxWindTodayKmh, 1) + ", Gust=" + String(out.maxGustTodayKmh, 1));
    } else {
        DataLogging::writeLog(LogLevel::LOG_ERROR, "Weather: Failed to parse required values from JSON response.");
    }
    return out.valid;
}


namespace Weather {

void init() {
    // Cette fonction est maintenant vide. L'initialisation se fait dans la boucle principale.
}

bool fetch() {
    WeatherData tempWeather;
    if (weatherFetchOnce(tempWeather)) {
        g_weather = tempWeather;
        return true;
    } else {
        // En cas d'échec, on garde les anciennes données météo mais on met à jour le code d'erreur
        g_weather.lastHttpCode = tempWeather.lastHttpCode;
        return false;
    }
}

} // namespace Weather
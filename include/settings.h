#pragma once
#include <Arduino.h>
#include <ArduinoJson.h>
#include <FS.h> // On utilise la classe de base FS pour être compatible SPIFFS et SD
#include <vector>
#include <functional>
#include "secrets.h"

// --- Structure de tous les paramètres configurables via le web ---
// (Aucun changement dans la structure elle-même)
struct AppSettings {
  // Affichage
  uint8_t  brightness_day;
  uint8_t  brightness_night;

  // Météo
  float    wind_alert_threshold_kmh;
  float    gust_alert_threshold_kmh;
  uint32_t weather_refresh_interval_ms;
  String   weather_url;

  // Humidité
  float    humidity_alert_threshold;
  
  // Seuil de température pour l'onduleur
  float    inverter_temp_alert_threshold_c;

  // Tâches planifiées
  uint8_t  daily_fetch_hour;
  uint8_t  daily_fetch_minute;
  
  // Paramètres matériel & calculs
  float    pv_max_watts;
  double   latitude;
  double   longitude;
  uint8_t  talon_start_hour;
  uint8_t  talon_end_hour;
  uint8_t  talon_end_minute;
  float    pac_min_temp_c;
  float    pac_max_temp_c;

  // Sonomètre
  float    sonometer_smoothing_factor;
  float    sonometer_mic_calibration_db_offset;

  // Topics MQTT (tous les topics restent ici)
  String   topic_piscine;
  String   topic_eau;
  String   topic_heure;
  String   topic_pompe_piscine;
  String   topic_pompe_hr_start;
  String   topic_pompe_hr_end;
  String   topic_autoconsommation;
  String   topic_prod_totale;
  String   topic_temp_onduleur_1;
  String   topic_temp_onduleur_2;
  String   topic_talon_eau;
  String   topic_studio_humidity;
  String   topic_sub_price;
  String   topic_hc_price;
  String   topic_bill_day;
  String   topic_hp_price;
  String   topic_sonometer_db;
  String   topic_sonometer_laeq60;
};

// --- Gestionnaire de paramètres ---
class SettingsManager {
public:
  using Listener = std::function<void(const AppSettings& newSettings, const AppSettings& oldSettings)>;

  bool begin(fs::FS& fs, const char* path="/config.json");
  bool load();
  bool save();
  bool updateFromJson(const JsonDocument& patch, bool saveAfter=true);
  void toJson(JsonDocument& doc) const;

  const AppSettings& get() const { return _settings; }
  void onChange(Listener cb) { _listeners.push_back(cb); }
  bool isLoaded() const { return _loaded; }

private:
  void notify(const AppSettings& now, const AppSettings& old);

  fs::FS* _fs = nullptr;
  const char* _path = "/config.json";
  bool _loaded = false;
  AppSettings _settings;
  std::vector<Listener> _listeners;
};

// Variables globales pour un accès simplifié aux paramètres
extern SettingsManager Settings;
extern AppSettings& SETTINGS;
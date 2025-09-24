#include "settings.h"

SettingsManager Settings;
AppSettings& SETTINGS = *const_cast<AppSettings*>(&Settings.get());

bool SettingsManager::begin(fs::FS& fs, const char* path) {
    _fs = &fs;
    _path = path;
    return load();
}

bool SettingsManager::load() {
    // Initialisation avec les valeurs par défaut
    _settings = AppSettings{
      .brightness_day = 255,
      .brightness_night = 60,
      .wind_alert_threshold_kmh = 22.0f,
      .gust_alert_threshold_kmh = 50.0f,
      .weather_refresh_interval_ms = 10UL * 60UL * 1000UL,
      .weather_url = "https://api.open-meteo.com/v1/forecast?latitude=49.21268&longitude=4.03642&current_weather=true&daily=windspeed_10m_max,windgusts_10m_max&timezone=auto&windspeed_unit=kmh",
      .humidity_alert_threshold = 70.0f,
      .inverter_temp_alert_threshold_c = 60.0f, // AJOUT : Valeur par défaut pour l'alerte de température
      .daily_fetch_hour = 6,
      .daily_fetch_minute = 30,
      .pv_max_watts = 2630.0f,
      .latitude = 49.212665,
      .longitude = 4.036408,
      .talon_start_hour = 3,
      .talon_end_hour = 4,
      .talon_end_minute = 30,
      .pac_min_temp_c = 1.5f,
      .pac_max_temp_c = 2.6f,
      .sonometer_smoothing_factor = 0.08f,
      .sonometer_mic_calibration_db_offset = 94.0f,
      .topic_piscine = "homie/homey/m5core2/devicecapabilities-texttext1",
      .topic_eau = "homie/homey/m5core2/devicecapabilities-texttext2",
      .topic_heure = "homie/homey/m5core2/devicecapabilities-texttext4",
      .topic_pompe_piscine = "homie/homey/pompe-de-la-piscine/onoff",
      .topic_pompe_hr_start = "homie/homey/m5core2/devicecapabilities-texttext5",
      .topic_pompe_hr_end = "homie/homey/m5core2/devicecapabilities-texttext6",
      .topic_autoconsommation = "homie/homey/solar-panel/measure-devicecapabilities-number-custom-35number8",
      .topic_prod_totale = "solar/ac/yieldtotal",
      .topic_temp_onduleur_1 = "solar/1164a00a05d6/0/temperature",
      .topic_temp_onduleur_2 = "solar/1410a015a55d/0/temperature",
      .topic_talon_eau = "homie/homey/conso-eau/measure-water",
      .topic_studio_humidity = "homie/homey/m5core2/devicecapabilities-number-custom-22number6",
      .topic_sub_price = "homie/homey/m5core2/devicecapabilities-numbernumber1",
      .topic_hc_price = "homie/homey/m5core2/devicecapabilities-numbernumber3",
      .topic_bill_day = "homie/homey/m5core2/devicecapabilities-numbernumber4",
      .topic_hp_price = "homie/homey/m5core2/devicecapabilities-numbernumber5",
      .topic_sonometer_db = "drums/monitor/db_meter",
      .topic_sonometer_laeq60 = "drums/monitor/laeq60"
    };

    File f = _fs->open(_path, FILE_READ);
    if (!f) { return save(); }

    JsonDocument doc;
    if (deserializeJson(doc, f) != DeserializationError::Ok) {
        f.close();
        Serial.println(F("[Settings] Erreur JSON, valeurs par défaut utilisées."));
        return false;
    }
    f.close();

    JsonObject o = doc.as<JsonObject>();
    #define LOAD_SETTING(key) if(!o[#key].isNull()){_settings.key = o[#key].as<decltype(_settings.key)>();}
    
    LOAD_SETTING(brightness_day);
    LOAD_SETTING(brightness_night);
    LOAD_SETTING(wind_alert_threshold_kmh);
    LOAD_SETTING(gust_alert_threshold_kmh);
    LOAD_SETTING(weather_refresh_interval_ms);
    LOAD_SETTING(weather_url);
    LOAD_SETTING(humidity_alert_threshold);
    LOAD_SETTING(inverter_temp_alert_threshold_c); // AJOUT
    LOAD_SETTING(daily_fetch_hour);
    LOAD_SETTING(daily_fetch_minute);
    LOAD_SETTING(pv_max_watts);
    LOAD_SETTING(latitude);
    LOAD_SETTING(longitude);
    LOAD_SETTING(talon_start_hour);
    LOAD_SETTING(talon_end_hour);
    LOAD_SETTING(talon_end_minute);
    LOAD_SETTING(pac_min_temp_c);
    LOAD_SETTING(pac_max_temp_c);
    LOAD_SETTING(sonometer_smoothing_factor);
    LOAD_SETTING(sonometer_mic_calibration_db_offset);
    LOAD_SETTING(topic_piscine);
    LOAD_SETTING(topic_eau);
    LOAD_SETTING(topic_heure);
    LOAD_SETTING(topic_pompe_piscine);
    LOAD_SETTING(topic_pompe_hr_start);
    LOAD_SETTING(topic_pompe_hr_end);
    LOAD_SETTING(topic_autoconsommation);
    LOAD_SETTING(topic_prod_totale);
    LOAD_SETTING(topic_temp_onduleur_1);
    LOAD_SETTING(topic_temp_onduleur_2);
    LOAD_SETTING(topic_talon_eau);
    LOAD_SETTING(topic_studio_humidity);
    LOAD_SETTING(topic_sub_price);
    LOAD_SETTING(topic_hc_price);
    LOAD_SETTING(topic_bill_day);
    LOAD_SETTING(topic_hp_price);
    LOAD_SETTING(topic_sonometer_db);
    LOAD_SETTING(topic_sonometer_laeq60);
    
    #undef LOAD_SETTING

    _loaded = true;
    return true;
}

bool SettingsManager::save() {
    if (!_fs) return false;
    JsonDocument doc;
    toJson(doc);
    File f = _fs->open(_path, FILE_WRITE);
    if (!f) {
        Serial.println(F("[Settings] Echec ouverture fichier pour écriture."));
        return false;
    }
    bool success = serializeJsonPretty(doc, f) > 0;
    f.close();
    return success;
}

void SettingsManager::toJson(JsonDocument& doc) const {
    JsonObject o = doc.to<JsonObject>();
    #define SAVE_SETTING(key) o[#key] = _settings.key
    
    SAVE_SETTING(brightness_day);
    SAVE_SETTING(brightness_night);
    SAVE_SETTING(wind_alert_threshold_kmh);
    SAVE_SETTING(gust_alert_threshold_kmh);
    SAVE_SETTING(weather_refresh_interval_ms);
    SAVE_SETTING(weather_url);
    SAVE_SETTING(humidity_alert_threshold);
    SAVE_SETTING(inverter_temp_alert_threshold_c); // AJOUT
    SAVE_SETTING(daily_fetch_hour);
    SAVE_SETTING(daily_fetch_minute);
    SAVE_SETTING(pv_max_watts);
    SAVE_SETTING(latitude);
    SAVE_SETTING(longitude);
    SAVE_SETTING(talon_start_hour);
    SAVE_SETTING(talon_end_hour);
    SAVE_SETTING(talon_end_minute);
    SAVE_SETTING(pac_min_temp_c);
    SAVE_SETTING(pac_max_temp_c);
    SAVE_SETTING(sonometer_smoothing_factor);
    SAVE_SETTING(sonometer_mic_calibration_db_offset);
    SAVE_SETTING(topic_piscine);
    SAVE_SETTING(topic_eau);
    SAVE_SETTING(topic_heure);
    SAVE_SETTING(topic_pompe_piscine);
    SAVE_SETTING(topic_pompe_hr_start);
    SAVE_SETTING(topic_pompe_hr_end);
    SAVE_SETTING(topic_autoconsommation);
    SAVE_SETTING(topic_prod_totale);
    SAVE_SETTING(topic_temp_onduleur_1);
    SAVE_SETTING(topic_temp_onduleur_2);
    SAVE_SETTING(topic_talon_eau);
    SAVE_SETTING(topic_studio_humidity);
    SAVE_SETTING(topic_sub_price);
    SAVE_SETTING(topic_hc_price);
    SAVE_SETTING(topic_bill_day);
    SAVE_SETTING(topic_hp_price);
    SAVE_SETTING(topic_sonometer_db);
    SAVE_SETTING(topic_sonometer_laeq60);
    
    #undef SAVE_SETTING
}

bool SettingsManager::updateFromJson(const JsonDocument& patch, bool saveAfter) {
    AppSettings old = _settings;
    bool changed = false;
    JsonObjectConst p = patch.as<JsonObjectConst>();

    #define UPDATE_IF(key) \
      if (!p[#key].isNull()) { \
        auto val = p[#key].as<decltype(_settings.key)>(); \
        if (_settings.key != val) { _settings.key = val; changed = true; } \
      }
      
    UPDATE_IF(brightness_day);
    UPDATE_IF(brightness_night);
    UPDATE_IF(wind_alert_threshold_kmh);
    UPDATE_IF(gust_alert_threshold_kmh);
    UPDATE_IF(weather_refresh_interval_ms);
    UPDATE_IF(weather_url);
    UPDATE_IF(humidity_alert_threshold);
    UPDATE_IF(inverter_temp_alert_threshold_c); // AJOUT
    UPDATE_IF(daily_fetch_hour);
    UPDATE_IF(daily_fetch_minute);
    UPDATE_IF(pv_max_watts);
    UPDATE_IF(latitude);
    UPDATE_IF(longitude);
    UPDATE_IF(talon_start_hour);
    UPDATE_IF(talon_end_hour);
    UPDATE_IF(talon_end_minute);
    UPDATE_IF(pac_min_temp_c);
    UPDATE_IF(pac_max_temp_c);
    UPDATE_IF(sonometer_smoothing_factor);
    UPDATE_IF(sonometer_mic_calibration_db_offset);
    UPDATE_IF(topic_piscine);
    UPDATE_IF(topic_eau);
    UPDATE_IF(topic_heure);
    UPDATE_IF(topic_pompe_piscine);
    UPDATE_IF(topic_pompe_hr_start);
    UPDATE_IF(topic_pompe_hr_end);
    UPDATE_IF(topic_autoconsommation);
    UPDATE_IF(topic_prod_totale);
    UPDATE_IF(topic_temp_onduleur_1);
    UPDATE_IF(topic_temp_onduleur_2);
    UPDATE_IF(topic_talon_eau);
    UPDATE_IF(topic_studio_humidity);
    UPDATE_IF(topic_sub_price);
    UPDATE_IF(topic_hc_price);
    UPDATE_IF(topic_bill_day);
    UPDATE_IF(topic_hp_price);
    UPDATE_IF(topic_sonometer_db);
    UPDATE_IF(topic_sonometer_laeq60);

    #undef UPDATE_IF

    if (changed) {
        if (saveAfter) save();
        notify(_settings, old);
    }
    return true;
}

void SettingsManager::notify(const AppSettings& now, const AppSettings& old) {
    for (auto& cb : _listeners) cb(now, old);
}
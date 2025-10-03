#include "threshold_supervisor.h"
#include "state.h"
#include "settings.h"
#include <Arduino.h>
#include "freertos/FreeRTOS.h"
#include "freertos/timers.h"

// Déclarations des variables externes
extern SensorData sensorData;
extern AppSettings& SETTINGS;

namespace ThresholdSupervisor {

// --- Fonctions "Getters" ---
// Le superviseur utilise ces fonctions pour obtenir les valeurs et les seuils.
// Cela le rend indépendant du reste de votre code.

// Récupère la température combinée des onduleurs
static float getFlammeValue() {
    // On prend la température la plus élevée des deux onduleurs
    if (sensorData.temp_onduleur1 > -100 && sensorData.temp_onduleur2 > -100) {
        return max(sensorData.temp_onduleur1, sensorData.temp_onduleur2);
    }
    if (sensorData.temp_onduleur1 > -100) {
        return sensorData.temp_onduleur1;
    }
    return sensorData.temp_onduleur2;
}

// Récupère le seuil d'alerte pour l'humidité
static float getGoutteThreshold() {
    return SETTINGS.humidity_alert_threshold;
}


// --- État interne du superviseur ---
static TimerHandle_t s_timer = nullptr; // Le timer pour la vérification périodique

// Variables volatiles car elles sont modifiées par des tâches/timers et lues par la boucle principale
static volatile bool s_goutteOn = false;
static volatile bool s_flammeOn = false;
static volatile uint8_t s_windLevel = 0;
static volatile uint32_t s_lastWindTs = 0;

// Mutex pour protéger l'accès aux variables ci-dessus lors de la lecture
static portMUX_TYPE s_mux = portMUX_INITIALIZER_UNLOCKED;


// --- Logique du superviseur ---

// Evalue l'alerte "flamme" (température onduleur)
static void evalFlammeOnce() {
    float currentValue = getFlammeValue();
    // Si la valeur n'est pas valide (ex: -999), on ne fait rien
    if (currentValue <= -100) return; 

    bool newState = (currentValue >= SETTINGS.inverter_temp_alert_threshold_c);
    
    // On met à jour l'état de manière sécurisée
    portENTER_CRITICAL(&s_mux);
    s_flammeOn = newState;
    portEXIT_CRITICAL(&s_mux);
}

// Cette fonction est appelée par le timer toutes les 10 secondes
static void vTimerCallback(TimerHandle_t) {
  evalFlammeOnce();
}

// Démarre le superviseur (appelé dans le setup)
void begin() {
  if (s_timer) return;
  // Crée un timer qui se répète toutes les 10 secondes
  s_timer = xTimerCreate("thr_sup", pdMS_TO_TICKS(10000), pdTRUE, nullptr, vTimerCallback);
  if (s_timer) xTimerStart(s_timer, 0);
}

// Fonction appelée quand de nouvelles données météo arrivent
void onWindFetch(float windKmh, uint32_t fetchEpochSeconds) {
  uint8_t level = 0;
  if (windKmh >= SETTINGS.gust_alert_threshold_kmh) {
      level = 2; // Niveau Rafale
  } else if (windKmh >= SETTINGS.wind_alert_threshold_kmh) {
      level = 1; // Niveau Vent
  }

  // On met à jour l'état de manière sécurisée
  portENTER_CRITICAL(&s_mux);
  s_windLevel = level;
  s_lastWindTs = fetchEpochSeconds;
  portEXIT_CRITICAL(&s_mux);
}

// Fonction appelée quand une nouvelle valeur d'humidité arrive par MQTT
void onGoutteMqtt(int value) {
    // La logique est simple : si la valeur est supérieure au seuil, l'alerte est active.
    bool newState = (value > getGoutteThreshold());

    portENTER_CRITICAL(&s_mux);
    s_goutteOn = newState;
    portEXIT_CRITICAL(&s_mux);
}

// Fonction que l'UI appelle pour savoir quoi afficher
States getStates() {
  States st;
  // On lit toutes les valeurs d'un coup de manière atomique et sécurisée
  portENTER_CRITICAL(&s_mux);
  st.goutteOn = s_goutteOn;
  st.flammeOn = s_flammeOn;
  st.windLevel = s_windLevel;
  st.lastWindTs = s_lastWindTs;
  portEXIT_CRITICAL(&s_mux);
  return st;
}

} // namespace ThresholdSupervisor
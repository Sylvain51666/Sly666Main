#pragma once
#include <stdint.h>

// Ce module est un superviseur léger pour les seuils d'alerte (vent, goutte, flamme).
// Il fonctionne en tâche de fond (timer FreeRTOS) sans jamais bloquer l'affichage.
// Les seuils et les valeurs sont récupérés via des fonctions externes pour garder le module générique.

namespace ThresholdSupervisor {

// Structure contenant l'état actuel des alertes.
// C'est cette structure que l'UI lira pour savoir quelles icônes afficher.
struct States {
  bool goutteOn;  // Alerte humidité
  bool flammeOn;  // Alerte température onduleur
  uint8_t windLevel;  // 0 = pas d'alerte, 1 = vent, 2 = rafale
  uint32_t lastWindTs;
};

// Fonctions pour "brancher" le superviseur au reste de l'application.
// Permet de lui dire où trouver les valeurs des capteurs et les seuils.
void setGetters(
    float (*getWindLowKmh)(),
    float (*getWindHighKmh)(),
    float (*getFlammeThreshold)(),
    uint32_t (*getNowEpoch)(),
    float (*getFlammeValue)()
);

// Démarre le superviseur. Crée et lance un timer de 10s pour la vérification de la "flamme".
void begin();

// Fonctions à appeler lors d'événements :

// À appeler après chaque récupération réussie de la météo.
void onWindFetch(float windKmh, uint32_t fetchEpochSeconds);

// À appeler depuis le callback MQTT quand le topic de l'humidité ("goutte") change.
void onGoutteMqtt(int value);

// Récupère l'état actuel des alertes de manière sécurisée (thread-safe).
States getStates();

} // namespace ThresholdSupervisor
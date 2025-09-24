# M5Core2 — Energy, Weather, Tesla & Home Monitor (V27)

![Architecture](assets/architecture_v27.png)

Projet ESP32 **M5Stack Core2** qui agrège météo, énergie, piscine, Tesla et MQTT dans une UI locale et un **serveur Web de configuration**.
Objectif: écran fiable 24/7, paramétrage dynamique, zéro blocage.

## Fonctionnalités clés
- UI locale M5Core2 basée sur **M5Unified + M5GFX**.
- Paramétrage **100 % dynamique via Web** et JSON persistant.
- Intégrations: Open‑Meteo (vent), MQTT maison, Tesla Fleet API, facturation électricité.
- Nouvel **Threshold Supervisor** léger pour *vent, goutte, flamme* sans toucher au graphisme ni au tactile.
- Logs et debug accessibles depuis la page Web de diagnostic.

## Matériel
- M5Stack Core2.
- Carte SD pour assets et JSON.
- Connexion Wi‑Fi stable.

## Stack firmware
- Arduino + PlatformIO, cible `m5stack-core2`.
- Libs principales: `M5Unified`, `M5GFX`, `ArduinoJson v7`, `PubSubClient`, `WiFiClientSecure`.

## Build
1. Ouvrir le dossier dans **PlatformIO**.
2. Brancher la Core2, sélectionner `m5stack-core2`.
3. `Build` puis `Upload`. Moniteur série à **115200**.

## Fichiers requis sur SD
- Images UI: `bg.jpg`, `bg_night.jpg`, `vent_fort.png`.
- JSON de config généré par le serveur Web.
- Dossier de logs si activé, ex. `/tesla_charges.json` en SPIFFS.

## Configuration Web
- Le serveur Web permet d’ajuster tous les seuils et options à chaud.
- Les **seuils restent dynamiques** et viennent du JSON. Le code de vérification ne les modifie pas.

## Threshold Supervisor
Module dédié qui évalue les icônes sans saturer le CPU ni casser le tactile:
- **Vent**: évalué uniquement à la fin de chaque fetch météo réussi. Aucune boucle supplémentaire. Ignorer données périmées.
- **Goutte**: évaluée à chaque changement de valeur du topic MQTT. Pas de polling.
- **Flamme**: évaluée par timer logiciel toutes les **10 s**. Règle simple `valeur >= seuil` sans hystérésis.
- Sortie unique: `getStates()` → `goutteOn`, `flammeOn`, `windLevel (0..2)`.
- Télémetrie: compteurs d’évaluations et de changements pour la page debug Web.

### Intégration rapide
```cpp
#include "threshold_supervisor.h"

void setup() {
  ThresholdSupervisor::begin();
  ThresholdSupervisor::setGetters(getWindLow, getWindHigh, getFlammeThr, getNowEpoch, getFlammeValue);
}

// À l’issue d’un fetch météo
ThresholdSupervisor::onWindFetch(windNowKmh, fetchEpochSeconds);

// Dans le callback MQTT du topic goutte
ThresholdSupervisor::onGoutteMqtt(value);

// Lecture états pour l’UI
auto st = ThresholdSupervisor::getStates();
```

## Tesla
- Refresh token validé. Fenêtre de polling planifiée la nuit pour exclure la charge de la ligne de base énergie.
- Sessions loguées en JSON sur SPIFFS si activé.

## Facturation électricité
- Calculs basés sur JSON quotidiens. Périodicité ajustable. Icônes de statut sur l’écran facture si un jour manque.

## Dépannage rapide
- Carte SD non montée: vérifier format et câblage. Le serveur Web reste accessible si réseau OK.
- Pas d’icône vent: vérifier que le fetch météo s’exécute. L’icône ne change que sur fetch réussi.
- Goutte figée: contrôler que le message MQTT est retained et que le callback est appelé.
- Flamme inactive: fournir un `getFlammeValue()` valide et un seuil dynamique dans la config.

## Licence
Usage interne. Adapter selon vos besoins.

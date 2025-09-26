#pragma once
#include <M5Unified.h>
#include "state.h"

namespace UI {
    // Fonctions d'initialisation et de nettoyage
    void init();
    
    // Fonctions de rafraîchissement de l'affichage
    void applyModeChangeNow();
    void updateAllDisplays();
    void updateAnimations();
    
    // Fonctions de gestion des icônes d'alerte
    void updateAlertIcons();

    // Fonctions de gestion des entrées utilisateur
    void handleInput();
    
    // Fonctions de gestion des modes et de la luminosité
    void updateBrightness();
    bool shouldBeNightMode();

    // Fonctions pour les écrans spéciaux
    void showErrorScreen();
}
// include/boot_ui.h
#pragma once

#include <M5Unified.h>

namespace BootUI {

/**
 * @brief Initialise et affiche l'écran de démarrage.
 */
void begin();

/**
 * @brief Met à jour la barre de progression.
 * @param percent Le pourcentage de progression (0-100).
 * @param label Le texte à afficher sous la barre.
 */
void setProgress(int percent, const String& label);

/**
 * @brief Met à jour la progression en fonction d'une étape.
 * @param stepIndex L'index de l'étape actuelle (commence à 1).
 * @param stepCount Le nombre total d'étapes.
 * @param label Le texte à afficher.
 */
void setStep(int stepIndex, int stepCount, const String& label);

/**
 * @brief Gère les animations de l'écran de démarrage.
 */
void loop();

/**
 * @brief Affiche le numéro de version sur l'écran de démarrage.
 * @param version La chaîne de caractères de la version (ex: "v1.0").
 */
void drawVersion(const char* version);

bool isActive();
void setActive(bool active);

} // namespace BootUI

//==================================================
// Fichier : include1/utils.h
//==================================================
#pragma once
#include <Arduino.h>
#include "state.h" // Inclut pour que les fonctions aient accès aux structs si besoin

namespace Utils {

// Déclaration de toutes les fonctions de utils.cpp
int daysInMonth(int y, int m);
double degToRad(double degrees);
double radToDeg(double radians);
int getDayOfYear(int year, int month, int day);
bool isDaylightSaving(struct tm* timeinfo);
void calculateSolarTimes();
bool isDaytimeByAstronomy();
String formatShelly3Chars(float watts);
String formatTemperature(float temp);
bool isValidTemperature(float temp, const String& source);
// MODIFICATION: La fonction splitString est supprimée car elle est remplacée par sscanf.

} // namespace Utilsa#pragma once
#include <Arduino.h>
#include "state.h"

namespace Utils {

// Fonctions utilitaires
int daysInMonth(int y, int m);
double degToRad(double degrees);
double radToDeg(double radians);
int getDayOfYear(int year, int month, int day);
bool isDaylightSaving(struct tm* timeinfo);

// Fonctions liées au temps et à l'affichage
void calculateSolarTimes();
bool isDaytimeByAstronomy();
String formatShelly3Chars(float watts);
String formatTemperature(float temp);
bool isValidTemperature(float temp, const String& source);

// Nouvelle fonction pour un redémarrage sécurisé
void safeReboot();

} // namespace Utils
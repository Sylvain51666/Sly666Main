// include/invoices_store.h
#pragma once
#include <Arduino.h>
#include <time.h>   // struct tm

namespace InvoicesStore {

// Types publics optionnels pour lecture UI
struct PeriodRange {
  struct tm start;
  struct tm end_inclusive; // dernier jour de la période à 23:59:59
};

struct TotalsPublic {
  double total_eur;
  double kwh;
  double hc_kwh;
  double hp_kwh;
  double sub_eur;
};

// Déjà existants
void init();
void handleMqttMessage(const String& topic, const String& payload);
void checkContinuityAndRefetch();
void triggerDailyUpdate();
void processExistingRawForCurrentPeriod();

// Nouveau pour l’archivage auto, appelé par l’écran factures
void ensurePrevArchiveExists();

// Getters non intrusifs pour l’écran (aucun recalcul)
PeriodRange getCurrentPeriod();
TotalsPublic getCurrentTotals();
void refreshFromDiskIfStale(); // no-op pour compat

} // namespace InvoicesStore

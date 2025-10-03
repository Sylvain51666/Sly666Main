const form = document.getElementById('settings-form');
const statusEl = document.getElementById('status');
const debugContainer = document.getElementById('debug-container');

// Crée les nouveaux boutons
const rebootBtn = document.getElementById('btn-reboot');
const saveBtn = document.getElementById('btn-save');
const recalcBtn = document.createElement('button');
recalcBtn.id = 'btn-recalc-invoices';
recalcBtn.className = 'btn';
recalcBtn.textContent = 'Recalculer les Factures';
// Insère le nouveau bouton à côté du bouton de sauvegarde
saveBtn.parentNode.insertBefore(recalcBtn, saveBtn.nextSibling);


function notify(ok, msg, duration = 3000){
  statusEl.className = 'status ' + (ok ? 'ok' : 'err');
  statusEl.textContent = msg;
  setTimeout(() => {
      if (statusEl.textContent === msg) {
          statusEl.className = 'status';
          statusEl.textContent = '';
      }
  }, duration);
}

// ... (Le reste des fonctions `inferType`, `buildField`, `groupTimeFields` reste identique) ...

let CURRENT_SETTINGS = {};
async function loadSettings(){
  const res = await fetch('/api/settings');
  if (!res.ok) throw new Error('GET /api/settings failed');
  const json = await res.json();
  delete json._meta;
  CURRENT_SETTINGS = json;
  renderForm(json);
}

function renderForm(obj){
  form.innerHTML = '';
  // ... (Le code de `renderForm` reste identique) ...
}

async function saveSettings(){
  const formData = new FormData(form);
  const patch = {};
  // ... (Le code de `saveSettings` reste identique) ...

  const res = await fetch('/api/settings', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(patch)
  });
  if (!res.ok){
    const txt = await res.text();
    notify(false, 'Erreur: ' + txt);
    return;
  }
  await res.json();
  notify(true, 'Paramètres sauvegardés.');
}

async function reboot(){
  if (!confirm("Êtes-vous sûr de vouloir redémarrer l'appareil ?")) return;
  // CORRECTION : On utilise la méthode POST pour le redémarrage
  const res = await fetch('/api/reboot', { method: 'POST' });
  if (res.ok) notify(true, 'Redémarrage en cours…');
  else notify(false, 'Échec du redémarrage.');
}

// NOUVELLE FONCTION : Appelle l'API pour recalculer les factures
async function recalculateInvoices() {
    if (!confirm("Ceci va forcer le recalcul de tous les totaux de la période de facturation en cours. Continuer ?")) return;
    notify(true, "Lancement du recalcul des factures en arrière-plan...", 5000);
    const res = await fetch('/api/recalculate-invoices');
    if (res.ok) {
        notify(true, "Recalcul démarré. Les totaux seront mis à jour dans quelques instants.", 5000);
    } else {
        notify(false, "Erreur lors du lancement du recalcul.");
    }
}


/** Crée une carte d'information pour une catégorie de données */
function createDebugCard(title, data) {
    // ... (Le code de `createDebugCard` reste identique) ...
}

/** Récupère les données de /api/debug et les affiche */
async function fetchAndRenderDebugInfo() {
  try {
    const res = await fetch('/api/debug');
    if (!res.ok) {
      debugContainer.textContent = `Erreur ${res.status}`;
      return;
    }
    const data = await res.json();
    
    debugContainer.innerHTML = '';
    
    if (data.system) debugContainer.appendChild(createDebugCard('Système', data.system));
    if (data.network) debugContainer.appendChild(createDebugCard('Réseau', data.network));
    if (data.power) debugContainer.appendChild(createDebugCard('Puissance', data.power));
    if (data.inverter) debugContainer.appendChild(createDebugCard('Onduleur', data.inverter));
    if (data.water) debugContainer.appendChild(createDebugCard('Eau', data.water));
    if (data.weather) debugContainer.appendChild(createDebugCard('Météo', data.weather));
    if (data.invoices_report) debugContainer.appendChild(createDebugCard('Facturation (API)', data.invoices_report));

  } catch (e) {
    debugContainer.textContent = 'Impossible de charger les données de débogage.';
    console.error(e);
  }
}

// Ajout des écouteurs d'événements
saveBtn.addEventListener('click', saveSettings);
rebootBtn.addEventListener('click', reboot);
recalcBtn.addEventListener('click', recalculateInvoices); // NOUVEAU

// Démarrage
loadSettings().catch(e=> notify(false, e.message));
fetchAndRenderDebugInfo();
setInterval(fetchAndRenderDebugInfo, 5000); // Rafraîchit les données de debug toutes les 5s
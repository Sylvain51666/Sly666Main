const form = document.getElementById('settings-form');
const statusEl = document.getElementById('status');
const debugContainer = document.getElementById('debug-container');

function notify(ok, msg){
  statusEl.className = 'status ' + (ok ? 'ok' : 'err');
  statusEl.textContent = msg;
}

function inferType(key, val){
  if (typeof val === 'boolean') return 'bool';
  if (typeof val === 'number') return 'number';
  if (typeof val === 'string') {
    if (key.toLowerCase().includes('password')) return 'password';
    return 'text';
  }
  return 'text';
}

function buildField(key, val){
  const type = inferType(key, val);
  const div = document.createElement('div');
  div.className = 'field';
  const label = document.createElement('label');
  label.className = 'label';
  label.textContent = key;
  div.appendChild(label);

  let input;
  if (type === 'bool'){
    const wrap = document.createElement('div');
    wrap.className = 'switch';
    input = document.createElement('input');
    input.type = 'checkbox';
    input.checked = !!val;
    wrap.appendChild(input);
    const span = document.createElement('span');
    span.textContent = val ? 'activé' : 'désactivé';
    input.addEventListener('change', ()=> span.textContent = input.checked ? 'activé' : 'désactivé');
    wrap.appendChild(span);
    div.appendChild(wrap);
  } else {
    input = document.createElement('input');
    input.className = 'input';
    input.type = type;
    input.value = val;
    if (key.toLowerCase().includes('port')) { input.min = 1; input.max = 65535; }
    if (key.endsWith('_hour')) { input.min = 0; input.max = 23; }
    if (key.endsWith('_minute')) { input.min = 0; input.max = 59; }
    div.appendChild(input);
  }
  input.name = key;
  return div;
}

function groupTimeFields(obj){
  const entries = Object.entries(obj);
  const groups = {};
  for (const [k,v] of entries){
    const m = k.match(/^(.*)_(hour|minute)$/);
    if (m){
      const base = m[1];
      groups[base] = groups[base] || {};
      groups[base][m[2]] = v;
    }
  }
  return groups;
}

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
  const timeGroups = groupTimeFields(obj);
  const allKeys = new Set(Object.keys(obj));

  const settingGroups = {
    'Réseau & Accès': ['wifi_ssid', 'wifi_password', 'mqtt_host', 'mqtt_port', 'mqtt_username', 'mqtt_password'],
    'Affichage': ['brightness_day', 'brightness_night'],
    'Planning': ['daily_fetch', 'talon_start', 'talon_end'],
    'Météo & Localisation': ['latitude', 'longitude', 'weather_url', 'weather_refresh_interval_ms'],
    'Seuils d\'Alerte': ['wind_alert_threshold_kmh', 'gust_alert_threshold_kmh', 'humidity_alert_threshold', 'inverter_temp_alert_threshold_c'],
    'Énergie & Matériel': ['pv_max_watts', 'pac_min_temp_c', 'pac_max_temp_c'],
    'Sonomètre': ['sonometer_mic_calibration_db_offset', 'sonometer_smoothing_factor'],
    'Topics MQTT': Object.keys(obj).filter(k => k.startsWith('topic_')).sort()
  };

  const renderedKeys = new Set();

  for (const [groupTitle, keysInGroup] of Object.entries(settingGroups)) {
    const relevantKeys = keysInGroup.filter(k => allKeys.has(k) || allKeys.has(k + '_hour'));
    if (relevantKeys.length === 0) continue;

    const titleEl = document.createElement('h3');
    titleEl.className = 'settings-group-title section';
    titleEl.textContent = groupTitle;
    form.appendChild(titleEl);

    for (const key of relevantKeys) {
      if (renderedKeys.has(key)) continue;

      if (key === 'weather_refresh_interval_ms') {
        const div = document.createElement('div');
        div.className = 'field';
        const label = document.createElement('label');
        label.className = 'label';
        label.textContent = 'weather_refresh_interval (minutes)';
        div.appendChild(label);
        const input = document.createElement('input');
        input.className = 'input';
        input.type = 'number';
        input.value = obj[key] / 60000;
        input.min = 1;
        input.name = 'weather_refresh_interval_min';
        div.appendChild(input);
        form.appendChild(div);
        renderedKeys.add(key);
        continue;
      }

      const base = key.replace(/_hour$/,'');
      
      if (timeGroups[base] && (obj.hasOwnProperty(base + '_hour') || obj.hasOwnProperty(base + '_minute'))) {
        const section = document.createElement('div');
        section.className = 'field';
        const label = document.createElement('label');
        label.className = 'label';
        label.textContent = base.replace(/_/g, ' ') + ' (hh:mm)';
        const input = document.createElement('input');
        input.type = 'time';
        input.className = 'input';
        const h = String(timeGroups[base].hour || 0).padStart(2,'0');
        const m = String(timeGroups[base].minute || 0).padStart(2,'0');
        input.value = `${h}:${m}`;
        input.name = base + '__time';
        section.appendChild(label);
        section.appendChild(input);
        form.appendChild(section);
        renderedKeys.add(base);
        renderedKeys.add(base + '_hour');
        renderedKeys.add(base + '_minute');
      } else if (obj.hasOwnProperty(key)) {
        form.appendChild(buildField(key, obj[key]));
        renderedKeys.add(key);
      }
    }
  }
}

async function saveSettings(){
  const formData = new FormData(form);
  const patch = {};

  if (formData.has('weather_refresh_interval_min')) {
    const minutes = Number(formData.get('weather_refresh_interval_min'));
    patch['weather_refresh_interval_ms'] = minutes * 60000;
    formData.delete('weather_refresh_interval_min');
  }

  for (const [name, value] of formData.entries()){
    if (name.endsWith('__time')) {
      const base = name.replace(/__time$/,'');
      const [h,m] = String(value).split(':').map(v=>parseInt(v||'0',10));
      patch[base + '_hour'] = h;
      patch[base + '_minute'] = m;
    } else {
      const sample = CURRENT_SETTINGS[name];
      if (typeof sample === 'number'){
        patch[name] = Number(value);
      } else if (typeof sample === 'boolean'){
        patch[name] = value === 'on';
      } else {
        patch[name] = String(value);
      }
    }
  }
  for (const el of form.querySelectorAll('input[type="checkbox"]')){
    if (!patch.hasOwnProperty(el.name)){
      patch[el.name] = el.checked;
    }
  }

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
  const updated = await res.json();
  CURRENT_SETTINGS = updated;
  notify(true, 'Paramètres sauvegardés.');
}

async function reboot(){
  const res = await fetch('/api/reboot');
  if (res.ok) notify(true, 'Redémarrage en cours…');
}

/** Crée une carte d'information pour une catégorie de données */
function createDebugCard(title, data) {
  const card = document.createElement('div');
  card.className = 'debug-card';

  const h3 = document.createElement('h3');
  h3.textContent = title;
  card.appendChild(h3);

  if (title === 'Facturation (API)') {
    const parsedData = {};
    if (typeof data === 'string') {
        const lines = data.split('\n').filter(line => line.trim() !== '' && line.includes(':'));
        for (const line of lines) {
            const parts = line.split(':');
            const key = parts[0].trim().replace(/_/g, ' ');
            const value = parts.slice(1).join(':').trim();
            if (key && value) {
                parsedData[key] = value;
            }
        }
        return createDebugCard('Facturation (API)', parsedData);
    }
  }

  for (const [key, value] of Object.entries(data)) {
    const item = document.createElement('div');
    item.className = 'debug-item';

    const keyEl = document.createElement('span');
    keyEl.className = 'debug-key';
    keyEl.textContent = key.replace(/_/g, ' ');
    item.appendChild(keyEl);

    const valueEl = document.createElement('span');
    valueEl.className = 'debug-value';
    valueEl.textContent = value;
    item.appendChild(valueEl);
    
    card.appendChild(item);
  }
  return card;
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

document.getElementById('btn-save').addEventListener('click', saveSettings);
document.getElementById('btn-reboot').addEventListener('click', reboot);

loadSettings().catch(e=> notify(false, e.message));

fetchAndRenderDebugInfo();
setInterval(fetchAndRenderDebugInfo, 3000);
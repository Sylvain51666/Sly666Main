<?php
// =====================================================
// index.php - NOUVEAU HUB DE SÉLECTION
// - Remplace l'ancienne redirection
// - Sécurisé par access_control.php
// - Affiche les choix "Ambulance" ou "Sacs DPS"
// - Déplace le popup de bienvenue ici
// - AJOUT: Boutons Admin/Login/Logout
// =====================================================

// On inclut notre nouveau garde de sécurité
require_once 'access_control.php';
require_once 'config.php'; // Pour UPLOAD_PATH (au cas où)

// On vérifie si un login est requis (rôle 'user' = accès de base)
// La fonction gère la redirection vers login.php si besoin.
require_login('user');

try {
    // On utilise les settings globaux chargés par access_control.php
    global $app_settings; // Contient TOUS les settings
    $settings = $app_settings;

} catch (PDOException $e) {
    error_log("Erreur récupération settings index: " . $e->getMessage());
    $settings = []; // S'assurer que $settings existe
}

// --- Préparer les injections CSS et JS (idem dps.php) ---
$cssOverrides = ":root {\n";
$jsConfig = "window.DYNAMIC_CONFIG = {\n";

foreach ($settings as $key => $value) {
    if (strpos($key, 'css_') === 0) {
        $cssKey = '--' . str_replace('css_', '', $key);
        $cssKey = str_replace('_', '-', $cssKey);
        $cssValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $cssOverrides .= "  " . $cssKey . ": " . $cssValue . ";\n";
    }
    elseif (strpos($key, 'app_') === 0) {
        $jsKey = str_replace('app_', '', $key);
        if ($key === 'app_requireLogin') {
            $jsValue = ($value === '1') ? 'true' : 'false';
        } elseif (is_numeric($value)) {
            $jsValue = (float)$value;
        } else {
            $jsValue = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $jsConfig .= "  " . json_encode($jsKey) . ": " . $jsValue . ",\n";
    }
}
$cssOverrides .= "}\n";
$jsConfig = rtrim($jsConfig, ",\n") . "\n};\n";

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sélection - CheckList</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">

    <style id="dynamic-css-settings">
    <?php echo $cssOverrides; ?>
    </style>

    <link rel="stylesheet" href="style.css?v=<?= file_exists('style.css') ? filemtime('style.css') : '1' ?>">
    
    <style>
        .selector-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: var(--spacing-lg);
            margin: var(--spacing-xl) 0;
        }

        .selector-card {
            background: var(--bg-pochette);
            padding: var(--spacing-lg);
            border-radius: var(--radius-lg);
            text-align: center;
            border: 1px solid var(--border-color);
            transition: all var(--transition-base);
            text-decoration: none;
            color: var(--text-primary);
        }

        .selector-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--color-primary);
        }

        .selector-card-img {
            width: 100%;
            height: 200px;
            object-fit: contain; /* Utiliser 'contain' pour ne pas couper les images */
            margin-bottom: var(--spacing-lg);
            border-radius: var(--radius-md);
            background: var(--bg-container);
            padding: var(--spacing-sm);
        }

        .selector-card h2 {
            font-size: 1.5rem;
            margin-bottom: var(--spacing-sm);
            color: var(--color-primary);
        }

        .selector-card p {
            font-size: 1rem;
            color: var(--text-secondary);
        }

        /* Animation Pulse/Vibrate */
        @keyframes pulse-vibrate {
            0% { transform: scale(1); }
            10% { transform: scale(1.03) rotate(1deg); }
            20% { transform: scale(1) rotate(-1deg); }
            30% { transform: scale(1.03) rotate(1deg); }
            40% { transform: scale(1) rotate(0deg); }
            100% { transform: scale(1); }
        }

        .selector-card.pulse-1 {
            /* Animation pulse-vibrate, 5s, démarre après 1s, en boucle */
            animation: pulse-vibrate 3s cubic-bezier(0.4, 0, 0.2, 1) 1s infinite;
        }
        
        .selector-card.pulse-2 {
            /* Même animation, mais démarre 2.5s plus tard (1s + 1.5s) */
             animation: pulse-vibrate 3s cubic-bezier(0.4, 0, 0.2, 1) 2.5s infinite;
        }
        
        .selector-card:hover {
            animation-play-state: paused; /* Arrête l'animation au survol */
        }
        
        /* === AJOUT DU STYLE POUR LES BOUTONS (identique à dps.php) === */
        #login-logout-button {
            background: var(--text-secondary);
        }
        #login-logout-button:hover {
            background: var(--text-primary);
        }
        /* Le bouton admin utilise le style bleu par défaut de style.css */
        
    </style>
</head>
<body>

    <div id="popup" class="show">
        <h1><?= htmlspecialchars($settings['app_text_welcomeTitle'] ?? 'Bienvenue') ?></h1>
        <p><?= $settings['app_text_welcomeMessage'] ?? 'Veuillez faire une sélection.' ?></p>
    </div>

    <div class="container">
        <div class="logo">
            <img src="https://static.wixstatic.com/media/f643e0_e7cb955f4fa14191bb309fafe25a6567~mv2.png/v1/fill/w_202,h_186,al_c,lg_1,q_85,enc_avif,quality_auto/UD%20LOGO.png" alt="Logo UD">
        </div>

        <div class="title">Check-List de l'UD pour les DPS</div>
        
        <p style="text-align: center; font-size: 1.1rem; color: var(--text-secondary);">
            Veuillez sélectionner le matériel à vérifier :
        </p>

        <div class="selector-grid">
            
            <a href="ambulance.php" class="selector-card pulse-1">
                <img src="img/Ambulance_dps.png" alt="Illustration Ambulance" class="selector-card-img">
                <h2>Ambulance</h2>
                <p>Vérification complète du véhicule et de ses dotations.</p>
            </a>
            
            <a href="dps.php" class="selector-card pulse-2">
                <img src="img/Sac_dps.png" alt="Illustration Sacs DPS" class="selector-card-img">
                <h2>Sacs DPS</h2>
                <p>Vérification des sacs de secours (Intervention / Prompt Secours).</p>
            </a>
            
        </div>
        
        <div class="actions">
            <?php if (check_permission('editor')): ?>
            <button id="admin-button" onclick="window.location.href='admin.php';">🔧 Administration</button>
            <?php endif; ?>
            
            <?php if (is_logged_in() && $_SESSION['username'] !== 'Anonyme'): ?>
                <button id="login-logout-button" onclick="window.location.href='logout.php';">🚪 Déconnexion</button>
            <?php elseif (($settings['app_requireLogin'] ?? '1') === '0'): ?>
                 <button id="login-logout-button" onclick="window.location.href='login.php';">🔑 Connexion</button>
            <?php endif; ?>
        </div>
        <div class="footer">
            D'après une idée de F. Lanfrey, création par S. Debrigode.
            <br>
            <?php if (is_logged_in()): ?>
                Connecté en tant que: <b><?= htmlspecialchars($_SESSION['username'] ?? 'N/A') ?></b> 
                <?php if ($_SESSION['username'] !== 'Anonyme'): ?>
                    (Rôle: <?= htmlspecialchars($_SESSION['role'] ?? 'N/A') ?>) 
                <?php endif; ?>
            <?php else: ?>
                 Mode Anonyme
            <?php endif; ?>
        </div>
    </div>

    <script id="dynamic-js-settings">
    <?php echo $jsConfig; ?>
    </script>
    
    <script src="app.js?v=<?= file_exists('app.js') ? filemtime('app.js') : '1' ?>"></script>
</body>
</html>
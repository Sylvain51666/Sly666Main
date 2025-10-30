<?php
// =====================================================
// ambulance.php - MODIFIÉ V7
// - Ajout récupération et affichage images (thumbnail/high_res)
//   depuis la table `ambu_items`.
// - AJOUT: CSS et HTML pour le modal de réinitialisation (identique à dps.php)
// - CORRECTION: Suppression du chargement de app.js (qui entrait en conflit)
// - MODIFIÉ V7: Texte bouton "Envoyer" en dur (comme dps.php)
// =====================================================

require_once 'access_control.php';
require_once 'config.php';
require_login('user');

try {
    // MODIFIÉ V5: Ajout i.thumbnail, i.high_res
    $stmt = $pdo->query("
        SELECT
            i.id, i.nom, i.quantite, i.sous_zone_nom,
            i.thumbnail, i.high_res, -- Ajout des colonnes images
            z.nom AS zone_nom, z.ordre AS zone_ordre, COALESCE(z.color, '#EEEEEE') AS zone_color,
            sz.ordre AS sous_zone_ordre, COALESCE(sz.color, '#FFFFFF') AS sous_zone_color
        FROM ambu_items AS i
        JOIN ambu_zones AS z ON i.zone_id = z.id
        LEFT JOIN ambu_sous_zones AS sz ON i.zone_id = sz.zone_id AND i.sous_zone_nom = sz.nom
        WHERE i.is_active = 1
        ORDER BY
            z.ordre,
            CASE WHEN i.sous_zone_nom IS NULL OR i.sous_zone_nom = '' THEN 1 ELSE 0 END,
            COALESCE(sz.ordre, 9999),
            i.sous_zone_nom,
            i.ordre
    ");
    $items_ambulance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    global $app_settings;
    $settings = $app_settings;

} catch (PDOException $e) {
    error_log("Erreur récupération items ambulance: " . $e->getMessage());
    $items_ambulance = []; $settings = [];
}

// Grouper par Zone (pochette)
$groupedItems = [];
foreach ($items_ambulance as $item) {
    $zone = $item['zone_nom'];
    if (!isset($groupedItems[$zone])) {
        $groupedItems[$zone] = ['items' => [], 'color' => $item['zone_color'] ?? '#EEEEEE'];
    }
    $groupedItems[$zone]['items'][] = $item;
}

// Préparer injections CSS/JS (inchangé)
$cssOverrides = ":root {\n"; $jsConfig = "window.DYNAMIC_CONFIG = {\n";
foreach ($settings as $key => $value) {
    if (strpos($key, 'css_') === 0) { $cssKey = '--' . str_replace(['css_', '_'], ['', '-'], $key); $cssValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); $cssOverrides .= "  $cssKey: $cssValue;\n"; }
    elseif (strpos($key, 'app_') === 0) {
        $jsKey = str_replace('app_', '', $key); $jsValue = $value;
        if ($key === 'app_requireLogin') { $jsValue = ($value === '1') ? 'true' : 'false'; }
        elseif (is_numeric($value)) { $jsValue = (float)$value; }
        else { $jsValue = json_encode($value, JSON_UNESCAPED_UNICODE); }
        $jsConfig .= "  " . json_encode($jsKey) . ": $jsValue,\n";
    }
}
$cssOverrides .= "}\n"; $jsConfig = rtrim($jsConfig, ",\n") . "\n};\n";

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-List Ambulance</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <style id="dynamic-css-settings"><?= $cssOverrides ?></style>
    <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
    <style>
        .sous-zone-titre { font-size: 1.1rem; font-weight: 600; color: var(--text-secondary); margin-top: var(--spacing-lg); margin-bottom: var(--spacing-sm); padding-bottom: var(--spacing-xs); border-bottom: 2px solid var(--border-color); }
        #back-button, #logout-button, #login-button { background: var(--text-secondary); }
        #back-button:hover, #logout-button:hover, #login-button:hover { background: var(--text-primary); }

        .product { position: relative; }
        .product::before {
            content: ''; display: block;
            position: absolute; top: 0; left: 0; width: 80px; height: 100%;
            opacity: 0; z-index: 0; pointer-events: none;
            background: linear-gradient(90deg, var(--sous-zone-color, transparent) 0%, transparent 100%);
            transition: opacity var(--transition-base), width var(--transition-base);
        }
        .product[data-sous-zone-color]:not([data-sous-zone-color=""]):not([data-sous-zone-color="#ffffff"])::before {
             opacity: 0.85;
        }
        .product[data-sous-zone-color]:not([data-sous-zone-color=""]):not([data-sous-zone-color="#ffffff"]):hover::before {
            opacity: 1; width: 100px;
        }

        /* === AJOUT V6: CSS POUR LE MODAL DE RESET === */
        .custom-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); justify-content: center; align-items: center; z-index: 10001; animation: fadeIn var(--transition-fast); backdrop-filter: blur(5px); }
        .custom-modal.show { display: flex; }
        .custom-modal-content { background: var(--bg-container); padding: var(--spacing-xl); border-radius: var(--radius-lg); box-shadow: var(--shadow-hover); max-width: 400px; width: 90%; text-align: center; animation: zoomIn var(--transition-slow); }
        .custom-modal-content h2 { font-size: 1.5rem; margin-top: 0; margin-bottom: var(--spacing-md); color: var(--text-primary); }
        .custom-modal-content p { font-size: 1rem; color: var(--text-secondary); margin-bottom: var(--spacing-lg); line-height: 1.6; }
        .custom-modal-actions { display: flex; gap: var(--spacing-md); justify-content: center; }
        .custom-modal-actions button { padding: var(--spacing-md) var(--spacing-lg); font-size: 1rem; font-weight: 600; border: none; border-radius: var(--radius-md); cursor: pointer; transition: all var(--transition-base); box-shadow: var(--shadow); flex: 1; }
        #reset-confirm-btn { background: var(--color-success); color: white; }
        #reset-confirm-btn:hover { background: #059669; box-shadow: var(--shadow-hover); }
        #reset-cancel-btn { background: var(--bg-pochette); color: var(--text-secondary); border: 2px solid var(--border-color); }
        #reset-cancel-btn:hover { background: var(--border-color); color: var(--text-primary); }
        /* === FIN AJOUT V6 === */

    </style>
</head>
<body>
    <div class="container">
        <div class="logo"><img src="https://static.wixstatic.com/media/f643e0_e7cb955f4fa14191bb309fafe25a6567~mv2.png/v1/fill/w_202,h_186,al_c,lg_1,q_85,enc_avif,quality_auto/UD%20LOGO.png" alt="Logo UD"></div>
        <div class="title">Check-List Ambulance</div>

        <?php $current_sous_zone_tracker = '---INIT---'; ?>
        <?php foreach ($groupedItems as $zone_nom => $data): ?>
        <div class="pochette" style="border-left-color: <?= htmlspecialchars($data['color']) ?>;">
            <h2><?= htmlspecialchars($zone_nom) ?></h2>
            <?php $current_sous_zone_tracker = '---INIT---'; ?>
            <?php foreach ($data['items'] as $item): ?>
                <?php
                $item_sous_zone = $item['sous_zone_nom'] ?? null;
                if ($item_sous_zone !== $current_sous_zone_tracker) {
                    $current_sous_zone_tracker = $item_sous_zone;
                    if ($current_sous_zone_tracker !== null && $current_sous_zone_tracker !== '') {
                        echo '<h3 class="sous-zone-titre">' . htmlspecialchars($current_sous_zone_tracker) . '</h3>';
                    }
                }
                $sous_zone_color = $item['sous_zone_color'] ?? '#FFFFFF';
                
                // === MODIFIÉ V5: Logique d'image (copiée de dps.php) ===
                $default_thumb = 'img/noimage.jpg';
                $default_high = 'img/noimage_high.jpg';
                $thumb_filename = $item['thumbnail'] ?? 'noimage.jpg';
                $high_res_filename = $item['high_res'] ?? 'noimage_high.jpg';
                $thumb_path = UPLOAD_PATH . '/' . $thumb_filename;
                $high_res_path = UPLOAD_PATH . '/' . $high_res_filename;
                
                $thumb_src = $default_thumb;
                if (file_exists($thumb_path) && !in_array($thumb_filename, ['noimage.jpg', 'default_thumb.jpg'])) {
                    $thumb_src = "img/" . htmlspecialchars($thumb_filename) . "?v=" . filemtime($thumb_path);
                }
                
                $high_res_src_attr = 'noimage_high.jpg';
                if (file_exists($high_res_path) && !in_array($high_res_filename, ['noimage_high.jpg', 'default_high.jpg'])) {
                    $high_res_src_attr = htmlspecialchars($high_res_filename); 
                }
                // === Fin Logique d'image ===
                ?>
                <div class="product"
                     data-product-id="<?= $item['id'] ?>"
                     data-pochette="<?= htmlspecialchars($zone_nom) ?>"
                     style="--sous-zone-color: <?= htmlspecialchars($sous_zone_color) ?>;"
                     data-sous-zone-color="<?= htmlspecialchars($sous_zone_color) ?>">
                    
                    <img src="<?= $thumb_src ?>" 
                         data-high-res="<?= $high_res_src_attr ?>" 
                         alt="Image de <?= htmlspecialchars($item['nom']) ?>">
                         
                    <div class="product-info">
                        <h3><?= htmlspecialchars($item['nom']) ?></h3>
                        <p>Quantité: <?= htmlspecialchars($item['quantite']) ?></p>
                        <div class="product-controls">
                            <select><option value="vide" selected>OK</option><option value="manquant">Manquant</option><option value="defaillant">Défaillant</option></select>
                            <label class="checkbox-wrapper"><input type="checkbox"><span class="ok">✓ Vérifié</span></label>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <div><textarea id="commentaire" placeholder="<?= htmlspecialchars($settings['app_text_ambu_commentPlaceholder'] ?? 'Commentaires...') ?>"></textarea></div>

        <div class="actions">
            <button id="send-button">✅ Envoyer</button>
            
            <button id="back-button" onclick="window.location.href='index.php';">← <?= htmlspecialchars($settings['app_text_ambu_backButton'] ?? 'Retour Sélection') ?></button>

            <?php if (check_permission('editor')): ?>
                <button id="admin-button" onclick="window.location.href='admin.php';">🔧 Administration</button>
            <?php endif; ?>

            <?php if (is_logged_in() && ($_SESSION['username'] ?? '') !== 'Anonyme'): ?>
                <button id="logout-button" onclick="window.location.href='logout.php';">🚪 Déconnexion</button>
            <?php elseif (($settings['app_requireLogin'] ?? '1') === '0'): ?>
                 <button id="login-button" onclick="window.location.href='login.php';">🔑 Connexion</button>
            <?php endif; ?>
        </div>

        <div class="footer">
            D'après une idée de F. Lanfrey, création par S. Debrigode.<br>
            <?php if (is_logged_in()): ?> Connecté: <b><?= htmlspecialchars($_SESSION['username'] ?? 'N/A') ?></b> <?php if (($_SESSION['username'] ?? '') !== 'Anonyme'): ?> (Rôle: <?= htmlspecialchars($_SESSION['role'] ?? 'N/A') ?>) <?php endif; ?>
            <?php else: ?> Mode Anonyme <?php endif; ?>
        </div>
    </div>

    <div id="image-modal"> <img src="" alt="Aperçu"> </div>

    <div id="reset-modal" class="custom-modal">
        <div class="custom-modal-content">
            <h2 id="reset-modal-title">Réinitialiser ?</h2>
            <p id="reset-modal-message">Voulez-vous réinitialiser le formulaire pour une nouvelle vérification ?</p>
            <div class="custom-modal-actions">
                <button id="reset-cancel-btn">Non, merci</button>
                <button id="reset-confirm-btn">Oui, réinitialiser</button>
            </div>
        </div>
    </div>
    <script id="dynamic-js-settings"><?= $jsConfig ?></script>
    
    <script src="app_ambu.js?v=<?= filemtime('app_ambu.js') ?>"></script>
</body>
</html>
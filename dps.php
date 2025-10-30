<?php
// =====================================================
// dps.php - MODIFIÉ
// - Sécurisé par access_control.php
// - Ajout bouton Login/Logout conditionnel
// - MODIFIÉ: Ajout fallback noimage.jpg si fichier absent
// - CORRIGÉ: Ajout de '??' pour éviter les erreurs PHP 8+ "Passing null"
// - AJOUT: HTML et CSS pour le modal de réinitialisation
// - MODIFIÉ V6: Injecte TOUS les paramètres 'app_' dans JS
// =====================================================

// On inclut notre nouveau garde de sécurité
require_once 'access_control.php';
require_once 'config.php'; // Pour UPLOAD_PATH

// On vérifie si un login est requis (rôle 'user' = dps.php)
require_login('user');

try {
    // Récupérer les produits avec leurs pochettes via la vue
    $stmt = $pdo->prepare("SELECT p.id, p.nom, p.quantite, COALESCE(po.nom, '') AS pochette_nom, COALESCE(po.color, '#FFFFFF') AS pochette_color, COALESCE(po.ordre, 9999) AS ordre, p.thumbnail, p.high_res FROM produits p LEFT JOIN pochettes po ON p.pochette_id = po.id WHERE p.deleted_at IS NULL AND p.is_active = 1 ORDER BY ordre ASC, p.id ASC");
$stmt->execute();
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // On utilise les settings globaux chargés par access_control.php
    global $app_settings; // Contient TOUS les settings
    $settings = $app_settings;

} catch (PDOException $e) {
    error_log("Erreur récupération produits: " . $e->getMessage());
    $produits = [];
    $settings = []; // S'assurer que $settings existe
}

// Grouper par pochette
$groupedProduits = [];
foreach ($produits as $produit) {
    $pochette = $produit['pochette_nom'] ?? 'Autres';
    if (!isset($groupedProduits[$pochette])) {
        $groupedProduits[$pochette] = [
            'color' => $produit['pochette_color'] ?? '#FFFFFF',
            'items' => []
        ];
    }
    $groupedProduits[$pochette]['items'][] = $produit;
}

// --- Préparer les injections CSS et JS ---
$cssOverrides = ":root {\n";
$jsConfig = "window.DYNAMIC_CONFIG = {\n";

foreach ($settings as $key => $value) {
    // Variables CSS (commencent par css_)
    if (strpos($key, 'css_') === 0) {
        $cssKey = '--' . str_replace('css_', '', $key);
        $cssKey = str_replace('_', '-', $cssKey); // Convertir snake_case en kebab-case
        // Sécuriser la valeur CSS (échapper les caractères spéciaux potentiels)
        $cssValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $cssOverrides .= "  " . $cssKey . ": " . $cssValue . ";\n";
    }
    // Variables JS (commencent par app_)
    elseif (strpos($key, 'app_') === 0) {
        // Convertir la clé PHP (app_maVariable) en clé JS (maVariable)
        $jsKey = str_replace('app_', '', $key);
        // Convertir snake_case en camelCase (optionnel mais courant en JS)
        // $jsKey = lcfirst(str_replace('_', '', ucwords($jsKey, '_')));
        
        // Gérer les types pour JS
        if ($key === 'app_requireLogin') {
            $jsValue = ($value === '1') ? 'true' : 'false'; // Booléen
        } elseif (is_numeric($value)) {
            $jsValue = (float)$value; // Nombre (int ou float)
        } else {
            // C'est une chaîne de caractères (textes des toasts, etc.)
            // On l'encode en JSON pour gérer les apostrophes, guillemets, etc.
            $jsValue = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        
        // Ajouter à l'objet JS
        // Utiliser json_encode pour la clé assure qu'elle est valide même si elle contient des caractères spéciaux
        $jsConfig .= "  " . json_encode($jsKey) . ": " . $jsValue . ",\n";
    }
}
$cssOverrides .= "}\n";
$jsConfig = rtrim($jsConfig, ",\n") . "\n};\n"; // Retirer la dernière virgule

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-List DPS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">

    <style id="dynamic-css-settings">
    <?php echo $cssOverrides; ?>
    </style>

    <link rel="stylesheet" href="style.css?v=<?= file_exists('style.css') ? filemtime('style.css') : '1' ?>">
    <style>
        /* Styles inline pour les dégradés dynamiques */
        <?php foreach ($groupedProduits as $pochette => $data): ?>
        .product[data-pochette="<?= htmlspecialchars($pochette ?? 'Autres') ?>"] {
            --pochette-color: <?= htmlspecialchars($data['color'] ?? '#FFFFFF') ?>;
        }
        <?php endforeach; ?>

        /* MODIFIÉ : Ajout de #back-button à la règle existante */
        #login-logout-button, #back-button {
            background: var(--text-secondary);
        }
        #login-logout-button:hover, #back-button:hover {
            background: var(--text-primary);
        }
        
        /* CSS POUR LE NOUVEAU MODAL DE RESET */
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
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <img src="https://static.wixstatic.com/media/f643e0_e7cb955f4fa14191bb309fafe25a6567~mv2.png/v1/fill/w_202,h_186,al_c,lg_1,q_85,enc_avif,quality_auto/UD%20LOGO.png" alt="Logo UD">
        </div>

        <div class="title">Check-List DPS</div>

        <?php foreach ($groupedProduits as $pochette => $data): ?>
        <div class="pochette" style="border-left-color: <?= htmlspecialchars($data['color'] ?? '#FFFFFF') ?>">
            <h2><?= htmlspecialchars($pochette ?? 'Autres') ?></h2>

            <?php foreach ($data['items'] as $produit): 
                $default_thumb = 'img/noimage.jpg';
                $default_high = 'img/noimage_high.jpg';
                $thumb_filename = $produit['thumbnail'] ?? 'noimage.jpg';
                $high_res_filename = $produit['high_res'] ?? 'noimage_high.jpg';
                $thumb_path = UPLOAD_PATH . '/' . $thumb_filename;
                $high_res_path = UPLOAD_PATH . '/' . $high_res_filename;
                $thumb_src = $default_thumb;
                if (file_exists($thumb_path) && $thumb_filename !== 'noimage.jpg' && $thumb_filename !== 'default_thumb.jpg') {
                    $thumb_src = "img/" . htmlspecialchars($thumb_filename) . "?v=" . filemtime($thumb_path);
                }
                $high_res_src_attr = 'noimage_high.jpg';
                if (file_exists($high_res_path) && $high_res_filename !== 'noimage_high.jpg' && $high_res_filename !== 'default_high.jpg') {
                    $high_res_src_attr = htmlspecialchars($high_res_filename); 
                }
            ?>
            <div class="product"
                 data-product-id="<?= $produit['id'] ?>"
                 data-pochette="<?= htmlspecialchars($pochette ?? 'Autres') ?>"
                 data-color="<?= htmlspecialchars($data['color'] ?? '#FFFFFF') ?>">
                <img src="<?= $thumb_src ?>"
                     data-high-res="<?= $high_res_src_attr ?>" 
                     alt="<?= htmlspecialchars($produit['nom'] ?? 'Image produit') ?>"> 
                <div class="product-info">
                    <h3><?= htmlspecialchars($produit['nom'] ?? 'Produit sans nom') ?></h3> 
                    <p>Quantité: <?= htmlspecialchars($produit['quantite'] ?? 'N/A') ?></p> 
                    <div class="product-controls">
                        <select>
                            <option value="vide" selected>OK</option>
                            <option value="manquant">Manquant</option>
                            <option value="defaillant">Défaillant</option>
                        </select>
                        <label class="checkbox-wrapper">
                            <input type="checkbox">
                            <span class="ok">✓ Vérifié</span>
                        </label>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <div>
            <textarea id="commentaire"
                      placeholder="Champ libre pour vos commentaires (max <?= htmlspecialchars($settings['app_maxCommentLength'] ?? '1000') ?> caractères)..."></textarea>
            </div>

        <div class="actions">
            <button id="send-button">✅ Envoyer</button>
            
            <button id="back-button" onclick="window.location.href='index.php';">← Retour Sélection</button>
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

    <script id="dynamic-js-settings">
    <?php echo $jsConfig; ?>
    </script>
    
    <script src="app.js?v=<?= file_exists('app.js') ? filemtime('app.js') : '1' ?>"></script>
</body>
</html>
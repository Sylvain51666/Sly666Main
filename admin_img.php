<?php
// =====================================================
// admin_img.php - MODIFIÉ V2 (Onglets DPS/Ambu)
// - Ajout onglets pour DPS et Ambulance
// - Gestion upload/update/delete images pour `produits` (DPS)
// - Gestion upload/update/delete images pour `ambu_items` (Ambu)
//   (NÉCESSITE `thumbnail` et `high_res` dans la table `ambu_items`)
// =====================================================

require_once 'access_control.php';
require_login('editor'); // Rôle 'editor' au minimum

$feedback = ['type' => '', 'message' => ''];
$pdo = $GLOBALS['pdo'];
require_once 'config.php'; // Pour les constantes (MAX_UPLOAD_SIZE etc)
// La fonction compressAndResizeImage est dans functions.php

$active_tab = 'dps'; // Onglet par défaut

// =====================================================
// Traitement POST (Mise à jour d'image)
// =====================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['image'])) {

    // --- Validation commune du fichier ---
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        // Gérer les erreurs d'upload standards
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse la taille autorisée par le serveur (php.ini).',
            UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la taille autorisée par le formulaire.',
            UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement téléversé.',
            UPLOAD_ERR_NO_FILE    => 'Aucun fichier n\'a été téléversé.',
            UPLOAD_ERR_NO_TMP_DIR => 'Erreur serveur : dossier temporaire manquant.',
            UPLOAD_ERR_CANT_WRITE => 'Erreur serveur : impossible d\'écrire sur le disque.',
            UPLOAD_ERR_EXTENSION  => 'Erreur serveur : une extension PHP a arrêté l\'envoi.',
        ];
        $error_code = $file['error'];
        $feedback = ['type' => 'error', 'message' => $upload_errors[$error_code] ?? 'Erreur inconnue lors du téléversement.'];
    
    } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
        $feedback = ['type' => 'error', 'message' => 'Fichier trop volumineux (Max: '. round(MAX_UPLOAD_SIZE / 1024 / 1024, 1) .'MB).'];
    
    } elseif (!is_valid_image($file['tmp_name'])) {
        $allowed_types_str = implode(', ', array_map(function($t){ return strtoupper(explode('/', $t)[1]); }, ALLOWED_IMAGE_TYPES));
        $feedback = ['type' => 'error', 'message' => 'Type de fichier non autorisé ('. $allowed_types_str .' uniquement).'];
    
    } else {
        // Le fichier est valide, on traite la cible (DPS ou Ambu)
        
        // --- GESTION DPS (SAC) ---
        if (isset($_POST['product_id'])) {
            $active_tab = 'dps';
            $product_id = (int)$_POST['product_id'];
            try {
                $stmt_prod = $pdo->prepare("SELECT nom, thumbnail, high_res FROM produits WHERE id = ?");
                $stmt_prod->execute([$product_id]);
                $produit = $stmt_prod->fetch();

                if ($produit) {
                    // Supprimer les anciennes images
                    delete_old_images($produit['thumbnail'], $produit['high_res']);

                    // Noms de fichiers uniques basés sur l'ID
                    $basename = 'prod_' . $product_id;
                    $thumb_name = $basename . '_thumb.jpg';
                    $high_res_name = $basename . '_high.jpg';
                    
                    // Créer les nouvelles images
                    create_images($file['tmp_name'], $thumb_name, $high_res_name);

                    // Mettre à jour la BDD
                    $stmt_update = $pdo->prepare("UPDATE produits SET thumbnail = ?, high_res = ? WHERE id = ?");
                    $stmt_update->execute([$thumb_name, $high_res_name, $product_id]);

                    log_event($pdo, $_SESSION['username'], "Mise à jour de l'image pour l'article DPS : ".$produit['nom']);
                    $feedback = ['type' => 'success', 'message' => 'Image mise à jour pour ' . htmlspecialchars($produit['nom'])];
                }
            } catch (Exception $e) { $feedback = ['type' => 'error', 'message' => 'Erreur DPS: ' . $e->getMessage()]; }
        
        // --- GESTION AMBULANCE ---
        } elseif (isset($_POST['item_id'])) {
            $active_tab = 'ambu';
            $item_id = (int)$_POST['item_id'];
            try {
                // On assume que les colonnes 'thumbnail' et 'high_res' existent
                $stmt_item = $pdo->prepare("SELECT nom, thumbnail, high_res FROM ambu_items WHERE id = ?");
                $stmt_item->execute([$item_id]);
                $item = $stmt_item->fetch();

                if ($item) {
                    // Supprimer les anciennes images
                    delete_old_images($item['thumbnail'], $item['high_res']);

                    // Noms de fichiers uniques basés sur l'ID
                    $basename = 'ambu_' . $item_id;
                    $thumb_name = $basename . '_thumb.jpg';
                    $high_res_name = $basename . '_high.jpg';

                    // Créer les nouvelles images
                    create_images($file['tmp_name'], $thumb_name, $high_res_name);
                    
                    // Mettre à jour la BDD (on assume que les colonnes existent)
                    $stmt_update = $pdo->prepare("UPDATE ambu_items SET thumbnail = ?, high_res = ? WHERE id = ?");
                    $stmt_update->execute([$thumb_name, $high_res_name, $item_id]);

                    log_event($pdo, $_SESSION['username'], "Mise à jour de l'image pour l'article Ambu : ".$item['nom']);
                    $feedback = ['type' => 'success', 'message' => 'Image mise à jour pour ' . htmlspecialchars($item['nom'])];
                }
            } catch (PDOException $e) {
                if ($e->getCode() == '42S22') { // Column not found
                    $feedback = ['type' => 'error', 'message' => 'Erreur BDD: Les colonnes `thumbnail` ou `high_res` sont manquantes dans la table `ambu_items`. Veuillez les ajouter.'];
                } else { $feedback = ['type' => 'error', 'message' => 'Erreur Ambu (PDO): ' . $e->getMessage()]; }
            } catch (Exception $e) { $feedback = ['type' => 'error', 'message' => 'Erreur Ambu: ' . $e->getMessage()]; }
        }
    }

    // Redirection pour éviter re-soumission
    header("Location: admin_img.php?tab=" . $active_tab . "&feedback_type=" . urlencode($feedback['type']) . "&feedback_msg=" . urlencode($feedback['message']));
    exit;
}

// --- Fonctions utilitaires locales ---
function delete_old_images($thumb_name, $high_res_name) {
    $default_thumbs = ['noimage.jpg', 'default_thumb.jpg'];
    $default_highs = ['noimage_high.jpg', 'default_high.jpg'];
    
    $old_thumb_path = UPLOAD_PATH . '/' . $thumb_name;
    $old_high_res_path = UPLOAD_PATH . '/' . $high_res_name;

    if (file_exists($old_thumb_path) && !in_array($thumb_name, $default_thumbs)) {
        @unlink($old_thumb_path);
    }
    if (file_exists($old_high_res_path) && !in_array($high_res_name, $default_highs)) {
        @unlink($old_high_res_path);
    }
}

function create_images($source_file, $thumb_name, $high_res_name) {
    $thumb_path = UPLOAD_PATH . '/' . $thumb_name;
    $high_res_path = UPLOAD_PATH . '/' . $high_res_name;

    if (!compressAndResizeImage($source_file, $high_res_path, MAX_IMAGE_WIDTH)) {
         throw new Exception("Échec de la création de l'image haute résolution.");
    }
    if (!compressAndResizeImage($source_file, $thumb_path, MAX_THUMBNAIL_WIDTH)) {
        throw new Exception("Échec de la création de la miniature.");
    }
}

// Récupérer le feedback et l'onglet actif depuis l'URL si redirection
if (isset($_GET['feedback_msg'])) { $feedback['type'] = $_GET['feedback_type'] ?? 'info'; $feedback['message'] = urldecode($_GET['feedback_msg']); }
if (isset($_GET['tab'])) { $active_tab = $_GET['tab'] === 'ambu' ? 'ambu' : 'dps'; }

// =====================================================
// Récupérer les données pour affichage
// =====================================================
try {
    // --- Données DPS ---
    $produits = $pdo->query("
        SELECT p.id, p.nom, p.thumbnail, p.high_res, po.nom AS pochette_nom 
        FROM produits p
        LEFT JOIN pochettes po ON p.pochette_id = po.id
        WHERE p.deleted_at IS NULL 
        ORDER BY po.ordre, p.nom
    ")->fetchAll(PDO::FETCH_ASSOC);

    // --- Données AMBULANCE ---
    // (On tente de les récupérer, en supposant que les colonnes existent)
    try {
        $ambu_items = $pdo->query("
            SELECT i.id, i.nom, i.thumbnail, i.high_res, z.nom AS zone_nom, i.sous_zone_nom 
            FROM ambu_items i
            LEFT JOIN ambu_zones z ON i.zone_id = z.id
            WHERE i.is_active = 1
            ORDER BY z.ordre, i.ordre, i.nom
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $ambu_items = []; // Échec si les colonnes n'existent pas
        if (!isset($feedback['message'])) {
             $feedback = ['type' => 'error', 'message' => 'Erreur chargement items Ambu: Les colonnes `thumbnail` ou `high_res` sont peut-être manquantes.'];
        }
    }

} catch (PDOException $e) {
    $produits = []; $ambu_items = [];
    $feedback = ['type' => 'error', 'message' => 'Impossible de charger les données: ' . $e->getMessage()];
}

// --- Fonction utilitaire locale pour l'affichage ---
function get_image_paths($thumb_name, $high_res_name) {
    $thumb_path = UPLOAD_PATH . '/' . $thumb_name;
    $high_res_path = UPLOAD_PATH . '/' . $high_res_name;
    
    $default_thumb_src = 'img/noimage.jpg';
    $default_high_src_attr = 'noimage_high.jpg';

    $thumb_src = $default_thumb_src;
    if (!empty($thumb_name) && file_exists($thumb_path) && $thumb_name !== 'noimage.jpg' && $thumb_name !== 'default_thumb.jpg') {
        $thumb_src = "img/" . htmlspecialchars($thumb_name) . "?v=" . filemtime($thumb_path);
    }
    
    $high_res_src_attr = $default_high_src_attr;
    if (!empty($high_res_name) && file_exists($high_res_path) && $high_res_name !== 'noimage_high.jpg' && $high_res_name !== 'default_high.jpg') {
        $high_res_src_attr = "img/" . htmlspecialchars($high_res_name) . "?v=" . filemtime($high_res_path);
    } elseif ($high_res_name !== 'noimage_high.jpg') {
         $high_res_src_attr = $default_high_src_attr;
    }

    return ['thumb' => $thumb_src, 'high' => $high_res_src_attr];
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Images - Admin</title>
    <link rel="stylesheet" href="style.css?v=<?= file_exists('style.css') ? filemtime('style.css') : '1' ?>">
    <style>
        /* Styles CSS (identiques à admin_checklist.php) */
        .admin-section { background: var(--bg-pochette); padding: var(--spacing-lg); border-radius: var(--radius-lg); margin-bottom: var(--spacing-xl); }
        .image-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--spacing-lg); }
        .image-card { background: var(--bg-container); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: var(--spacing-md); text-align: center; display: flex; flex-direction: column; justify-content: space-between; }
        .image-card-img-container { width: 100%; height: 150px; margin-bottom: var(--spacing-md); cursor: pointer; overflow: hidden; border-radius: var(--radius-sm); border: 1px solid var(--border-color); position: relative; }
        .image-card img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform var(--transition-base), filter var(--transition-fast); }
        .image-card-img-container:hover img { transform: scale(1.05); }
        .image-card h3 { font-size: 1rem; margin-bottom: var(--spacing-sm); color: var(--text-primary); word-break: break-word; }
        
        .image-card .pochette-name {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-style: italic;
            margin-bottom: var(--spacing-md);
            word-break: break-word;
        }
        
        .image-card form { margin-top: auto; }
        .image-card input[type="file"] { width: 100%; margin-bottom: var(--spacing-sm); font-size: 0.8rem; padding: 5px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-body); cursor: pointer; }
        input[type="file"]::-webkit-file-upload-button { visibility: hidden; display: none; }
        input[type="file"]::before { content: 'Choisir image'; display: inline-block; background: var(--bg-pochette); border: 1px solid var(--border-color); border-radius: 3px; padding: 5px 8px; outline: none; white-space: nowrap; cursor: pointer; font-weight: 600; font-size: 0.8rem; margin-right: 5px; }
        input[type="file"]:hover::before { border-color: var(--color-primary); }

        .image-card button { width: 100%; padding: var(--spacing-sm) var(--spacing-md); border: none; border-radius: var(--radius-md); background: var(--color-primary); color: white; font-size: 0.9rem; font-weight: 600; cursor: pointer; margin-top: var(--spacing-sm); transition: all var(--transition-base); }
        .image-card button:hover:not(:disabled) { background: var(--color-primary-hover); transform: translateY(-1px); }
        .image-card button:disabled { background: var(--text-secondary); cursor: not-allowed; }

        .image-card button.needs-update {
            background: linear-gradient(135deg, var(--color-warning), #d97706);
            animation: pulse 1.5s infinite;
        }
        .image-card button.needs-update:hover:not(:disabled) {
             background: linear-gradient(135deg, #d97706, var(--color-warning));
             animation-play-state: paused;
        }
        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); }
            70% { transform: scale(1.02); box-shadow: 0 0 0 10px rgba(245, 158, 11, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        }

        .image-card img.previewing {
            filter: grayscale(50%) brightness(0.9);
        }
        .image-card-img-container.previewing::after {
             content: 'Prévisualisation';
             position: absolute;
             bottom: 5px;
             left: 50%;
             transform: translateX(-50%);
             background: rgba(0,0,0,0.7);
             color: white;
             padding: 3px 8px;
             border-radius: var(--radius-sm);
             font-size: 0.7rem;
             font-weight: bold;
             opacity: 1;
             transition: opacity var(--transition-fast);
             pointer-events: none;
             z-index: 2;
        }
        .image-card-img-container::after {
             content: '';
             position: absolute;
             opacity: 0;
        }

        /* Styles Modal (copiés de l'ancienne version) */
        #image-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.9); justify-content: center; align-items: center; z-index: 9999; cursor: pointer; animation: fadeIn var(--transition-base); }
        #image-modal.show { display: flex; }
        #image-modal img { max-width: 90%; max-height: 90%; border-radius: var(--radius-lg); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); animation: zoomIn var(--transition-slow); }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="text-align: center; margin-bottom: 2rem;">🖼️ Gestion des Images</h1>

        <?php if (!empty($feedback['message'])): ?>
            <div class="alert alert-<?= $feedback['type'] === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($feedback['message']) ?>
            </div>
        <?php endif; ?>

        <div class="tabs-container">
            <button class="tab-link <?= $active_tab === 'dps' ? 'active' : '' ?>" data-tab="dps">Sacs DPS</button>
            <button class="tab-link <?= $active_tab === 'ambu' ? 'active' : '' ?>" data-tab="ambu">Ambulances</button>
        </div>

        <div id="dps" class="tab-content <?= $active_tab === 'dps' ? 'active' : '' ?>">
            <div class="admin-section">
                <h2>Mettre à jour les images (DPS)</h2>
                <p style="color: var(--text-secondary); margin-bottom: var(--spacing-lg);">
                    Cliquez sur une miniature pour la voir en grand. Choisissez une nouvelle image (JPG/PNG, max <?= round(MAX_UPLOAD_SIZE / 1024 / 1024, 1) ?>MB) pour la prévisualiser, puis cliquez sur "Mettre à jour" pour l'enregistrer.
                </p>

                <div class="image-grid">
                    <?php foreach ($produits as $produit): 
                        $paths = get_image_paths($produit['thumbnail'] ?? null, $produit['high_res'] ?? null);
                    ?>
                    <div class="image-card" id="card-dps-<?= $produit['id'] ?>"> 
                        <div>
                            <div class="image-card-img-container"
                                 data-high-res="<?= $paths['high'] ?>" 
                                 title="Cliquez pour agrandir">
                                <img src="<?= $paths['thumb'] ?>"
                                     alt="Miniature de <?= htmlspecialchars($produit['nom']) ?>"
                                     id="img-preview-dps-<?= $produit['id'] ?>"> 
                            </div>
                            <h3><?= htmlspecialchars($produit['nom']) ?></h3>
                            <div class="pochette-name"><?= htmlspecialchars($produit['pochette_nom'] ?? 'N/A') ?></div>
                        </div>

                        <form action="admin_img.php" method="POST" enctype="multipart/form-data" class="upload-form">
                            <input type="hidden" name="active_tab" value="dps">
                            <input type="hidden" name="product_id" value="<?= $produit['id'] ?>">
                            <input type="file" name="image" accept="image/jpeg,image/png" class="image-input" data-preview-target="img-preview-dps-<?= $produit['id'] ?>" required>
                            <button type="submit" class="update-button">Mettre à jour</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="ambu" class="tab-content <?= $active_tab === 'ambu' ? 'active' : '' ?>">
            <div class="admin-section">
                <h2>Mettre à jour les images (Ambulance)</h2>
                 <p style="color: var(--text-secondary); margin-bottom: var(--spacing-lg);">
                    Même fonctionnement que pour les sacs DPS. Les images apparaîtront sur la checklist ambulance (si le fichier `ambulance.php` a aussi été mis à jour).
                </p>

                <div class="image-grid">
                    <?php foreach ($ambu_items as $item): 
                        $paths = get_image_paths($item['thumbnail'] ?? null, $item['high_res'] ?? null);
                    ?>
                    <div class="image-card" id="card-ambu-<?= $item['id'] ?>"> 
                        <div>
                            <div class="image-card-img-container"
                                 data-high-res="<?= $paths['high'] ?>" 
                                 title="Cliquez pour agrandir">
                                <img src="<?= $paths['thumb'] ?>"
                                     alt="Miniature de <?= htmlspecialchars($item['nom']) ?>"
                                     id="img-preview-ambu-<?= $item['id'] ?>"> 
                            </div>
                            <h3><?= htmlspecialchars($item['nom']) ?></h3>
                            <div class="pochette-name">
                                <?= htmlspecialchars($item['zone_nom'] ?? 'N/A') ?>
                                <?php if (!empty($item['sous_zone_nom'])): ?>
                                    - <?= htmlspecialchars($item['sous_zone_nom']) ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form action="admin_img.php" method="POST" enctype="multipart/form-data" class="upload-form">
                            <input type="hidden" name="active_tab" value="ambu">
                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                            <input type="file" name="image" accept="image/jpeg,image/png" class="image-input" data-preview-target="img-preview-ambu-<?= $item['id'] ?>" required>
                            <button type="submit" class="update-button">Mettre à jour</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                     <?php if (empty($ambu_items) && empty($feedback['message'])): ?>
                        <p style="color: var(--text-secondary); font-style: italic;">Aucun item ambulance actif trouvé.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>


        <div class="actions" style="margin-top: var(--spacing-xl);">
            <button onclick="window.location.href='admin.php';">← Retour Admin</button>
        </div>
    </div>

    <div id="image-modal">
        <img src="" alt="Aperçu haute résolution">
    </div>

    <script>
        // Drapeau global pour les changements non sauvegardés
        let hasUnsavedChanges = false;
        // Pour suivre quels formulaires ont des changements
        const changedForms = new Set();

        document.addEventListener('DOMContentLoaded', function() {
            
            // --- 1. GESTION DES ONGLETS ---
            const tabLinks = document.querySelectorAll('.tab-link');
            const tabContents = document.querySelectorAll('.tab-content');
            const setActiveTab = (tabId) => {
                tabLinks.forEach(l => l.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));
                document.querySelector(`.tab-link[data-tab="${tabId}"]`)?.classList.add('active');
                document.getElementById(tabId)?.classList.add('active');
                // Met à jour les champs cachés 'active_tab' dans tous les formulaires
                document.querySelectorAll('form input[name="active_tab"]').forEach(input => {
                    input.value = tabId;
                });
                 try { window.location.hash = tabId; } catch(e) {}
            };
            tabLinks.forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    setActiveTab(link.getAttribute('data-tab'));
                });
            });
            // Lire l'onglet depuis l'URL hash au chargement OU utiliser celui de PHP
            let currentTab = '<?= $active_tab ?>';
            if(window.location.hash) {
                const hashTab = window.location.hash.substring(1);
                if(document.getElementById(hashTab)) {
                    if (!<?= isset($_GET['feedback_msg']) ? 'true' : 'false' ?>) {
                        currentTab = hashTab;
                    }
                }
            }
            setActiveTab(currentTab);


            // --- 2. Gestion du Modal (clic pour agrandir) ---
            const modal = document.getElementById('image-modal');
            const modalImage = modal ? modal.querySelector('img') : null;
            // Note: On sélectionne tous les containers des DEUX onglets
            const imageContainers = document.querySelectorAll('.image-card-img-container');
            
            imageContainers.forEach(container => {
                container.addEventListener('click', function(event) {
                    if(event.target.closest('form')) return; 

                    let highResSrc = this.getAttribute('data-high-res');
                    if (highResSrc === 'noimage_high.jpg') {
                        highResSrc = 'img/' + highResSrc;
                    }
                    
                    if (highResSrc && modalImage && modal) {
                        modalImage.src = highResSrc;
                        modal.classList.add('show');
                    }
                });
            });

            if(modal) {
                modal.addEventListener('click', function() {
                    modal.classList.remove('show');
                    if(modalImage) {
                         setTimeout(() => { modalImage.src = ''; }, 300);
                    }
                });
            }

            // --- 3. Gestion de la Prévisualisation et du bouton sexy ---
            const fileInputs = document.querySelectorAll('.image-input');
            const uploadForms = document.querySelectorAll('.upload-form');

            fileInputs.forEach(input => {
                input.addEventListener('change', function(event) {
                    const file = event.target.files[0];
                    const previewTargetId = input.getAttribute('data-preview-target');
                    const previewImage = document.getElementById(previewTargetId);
                    const previewContainer = previewImage ? previewImage.closest('.image-card-img-container') : null;
                    const form = input.closest('.upload-form');
                    const updateButton = form ? form.querySelector('.update-button') : null;

                    if (previewImage) previewImage.classList.remove('previewing');
                    if (previewContainer) previewContainer.classList.remove('previewing');
                    if (updateButton) updateButton.classList.remove('needs-update');
                    if (form) changedForms.delete(form);

                    if (file && previewImage && updateButton && previewContainer && form) {
                        const allowedTypes = ['image/jpeg', 'image/png'];
                        if (!allowedTypes.includes(file.type)) {
                            alert('Type de fichier non autorisé (JPG ou PNG uniquement).');
                            input.value = ''; return;
                        }
                        const maxSize = <?= defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : 5*1024*1024 ?>;
                        if (file.size > maxSize) {
                            alert('Fichier trop volumineux (Max: <?= defined('MAX_UPLOAD_SIZE') ? round(MAX_UPLOAD_SIZE / 1024 / 1024, 1) : 5 ?> MB).');
                            input.value = ''; return;
                        }

                        const reader = new FileReader();
                        reader.onload = function(e) {
                            previewImage.src = e.target.result;
                            previewImage.classList.add('previewing');
                            previewContainer.classList.add('previewing');
                            updateButton.classList.add('needs-update');
                            changedForms.add(form);
                            hasUnsavedChanges = true;
                        }
                        reader.readAsDataURL(file);
                    }
                });
            });

            // --- 4. Gestion Alerte de Sortie ---
            window.addEventListener('beforeunload', function (e) {
                if (changedForms.size > 0) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            // --- 5. Réinitialiser le drapeau après soumission ---
             uploadForms.forEach(form => {
                form.addEventListener('submit', function() {
                    const updateButton = form.querySelector('.update-button');
                    if (updateButton && updateButton.classList.contains('needs-update')) {
                        changedForms.delete(form);
                        if (changedForms.size === 0) {
                            hasUnsavedChanges = false;
                        }
                        updateButton.textContent = 'Mise à jour...';
                        updateButton.disabled = true;
                    }
                });
            });

        });
    </script>

</body>
</html>
<?php
// =====================================================
// ADMIN_STRUCTURE.PHP - Gestion des structures DPS & Ambulance
// VERSION COMPLÈTE avec interface à onglets
// =====================================================

session_start();
require_once 'db_connection.php';
require_once 'access_control.php';

// Seuls admin et editor peuvent accéder
require_login('editor');

$feedback = ['type' => '', 'message' => ''];
$pdo = $GLOBALS['pdo'];

// Déterminer l'onglet actif (par défaut: DPS)
$activeTab = $_GET['tab'] ?? 'dps';
if (!in_array($activeTab, ['dps', 'ambulance'])) {
    $activeTab = 'dps';
}

// ========== HANDLERS AJAX ==========

// --- DPS: Drag-and-Drop Pochettes ---
if (isset($_POST['update_pochette_order'])) {
    header('Content-Type: application/json');
    $order = json_decode($_POST['order'] ?? '[]', true);
    if (is_array($order) && !empty($order)) {
        try {
            $pdo->beginTransaction();
            foreach ($order as $index => $id) {
                $stmt = $pdo->prepare("UPDATE pochettes SET ordre = ? WHERE id = ?");
                $stmt->execute([$index + 1, (int)$id]);
            }
            $pdo->commit();
            log_event($pdo, $_SESSION['username'], "[DPS] Réorganisation drag-and-drop des pochettes.");
            echo json_encode(['success' => true, 'message' => 'Ordre sauvegardé.']);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Données invalides.']);
    exit;
}

// --- AMBULANCE: Drag-and-Drop Zones ---
if (isset($_POST['update_ambu_zone_order'])) {
    header('Content-Type: application/json');
    $order = json_decode($_POST['order'] ?? '[]', true);
    if (is_array($order) && !empty($order)) {
        try {
            $pdo->beginTransaction();
            foreach ($order as $index => $id) {
                $stmt = $pdo->prepare("UPDATE ambu_zones SET ordre = ? WHERE id = ?");
                $stmt->execute([$index + 1, (int)$id]);
            }
            $pdo->commit();
            log_event($pdo, $_SESSION['username'], "[AMBU] Réorganisation drag-and-drop des zones.");
            echo json_encode(['success' => true, 'message' => 'Ordre sauvegardé.']);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Données invalides.']);
    exit;
}

// --- AMBULANCE: Récupérer sous-zones existantes (autocomplete) ---
if (isset($_GET['get_sous_zones'])) {
    header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT DISTINCT sous_zone_nom FROM ambu_items WHERE sous_zone_nom IS NOT NULL ORDER BY sous_zone_nom ASC");
    $sousZones = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($sousZones);
    exit;
}

// ========== TRAITEMENTS POST ==========

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // ========== DPS: POCHETTES ==========
    
    if (isset($_POST['add_pochette'])) {
        $nom = trim($_POST['pochette_nom']);
        $color = trim($_POST['pochette_color']) ?: '#FFFFFF';
        if (empty($nom)) {
            $feedback = ['type' => 'error', 'message' => 'Le nom de la pochette est requis.'];
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO pochettes (nom, color, ordre) VALUES (?, ?, (SELECT COALESCE(MAX(ordre), 0) + 1 FROM pochettes p))");
                $stmt->execute([$nom, $color]);
                log_event($pdo, $_SESSION['username'], "[DPS] Ajout pochette: $nom");
                $feedback = ['type' => 'success', 'message' => 'Pochette ajoutée avec succès.'];
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }
    
    if (isset($_POST['edit_pochette'])) {
        $id = (int)$_POST['pochette_id'];
        $nom = trim($_POST['pochette_nom']);
        $color = trim($_POST['pochette_color']) ?: '#FFFFFF';
        if (empty($nom)) {
            $feedback = ['type' => 'error', 'message' => 'Le nom de la pochette est requis.'];
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE pochettes SET nom = ?, color = ? WHERE id = ?");
                $stmt->execute([$nom, $color, $id]);
                log_event($pdo, $_SESSION['username'], "[DPS] Modification pochette ID $id: $nom");
                $feedback = ['type' => 'success', 'message' => 'Pochette modifiée avec succès.'];
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }
    
    if (isset($_POST['delete_pochette'])) {
        $id = (int)$_POST['pochette_id'];
        try {
            $stmt = $pdo->prepare("SELECT nom FROM pochettes WHERE id = ?");
            $stmt->execute([$id]);
            $nom = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM pochettes WHERE id = ?");
            $stmt->execute([$id]);
            log_event($pdo, $_SESSION['username'], "[DPS] Suppression pochette ID $id: $nom");
            $feedback = ['type' => 'success', 'message' => 'Pochette supprimée avec succès.'];
        } catch (PDOException $e) {
            $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
        }
    }
    
    // ========== DPS: PRODUITS ==========
    
    if (isset($_POST['add_produit'])) {
        $nom = trim($_POST['produit_nom']);
        $quantite = trim($_POST['produit_quantite']);
        $pochette_id = (int)$_POST['pochette_id'];
        
        if (empty($nom) || empty($pochette_id)) {
            $feedback = ['type' => 'error', 'message' => 'Le nom et la pochette sont requis.'];
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO produits (nom, quantite, pochette_id, ordre) VALUES (?, ?, ?, (SELECT COALESCE(MAX(ordre), 0) + 1 FROM produits p WHERE p.pochette_id = ?))");
                $stmt->execute([$nom, $quantite, $pochette_id, $pochette_id]);
                log_event($pdo, $_SESSION['username'], "[DPS] Ajout produit: $nom (Pochette ID $pochette_id)");
                $feedback = ['type' => 'success', 'message' => 'Produit ajouté avec succès.'];
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }
    
    if (isset($_POST['edit_produit'])) {
        $id = (int)$_POST['produit_id'];
        $nom = trim($_POST['produit_nom']);
        $quantite = trim($_POST['produit_quantite']);
        $pochette_id = (int)$_POST['pochette_id'];
        
        if (empty($nom) || empty($pochette_id)) {
            $feedback = ['type' => 'error', 'message' => 'Le nom et la pochette sont requis.'];
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE produits SET nom = ?, quantite = ?, pochette_id = ? WHERE id = ?");
                $stmt->execute([$nom, $quantite, $pochette_id, $id]);
                log_event($pdo, $_SESSION['username'], "[DPS] Modification produit ID $id: $nom");
                $feedback = ['type' => 'success', 'message' => 'Produit modifié avec succès.'];
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }
    
    if (isset($_POST['delete_produit'])) {
        $id = (int)$_POST['produit_id'];
        try {
            $stmt = $pdo->prepare("SELECT nom FROM produits WHERE id = ?");
            $stmt->execute([$id]);
            $nom = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM produits WHERE id = ?");
            $stmt->execute([$id]);
            log_event($pdo, $_SESSION['username'], "[DPS] Suppression produit ID $id: $nom");
            $feedback = ['type' => 'success', 'message' => 'Produit supprimé avec succès.'];
        } catch (PDOException $e) {
            $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
        }
    }
    
    // ========== AMBULANCE: ZONES ==========
    
    if (isset($_POST['add_ambu_zone'])) {
        $nom = trim($_POST['zone_nom']);
        $color = trim($_POST['zone_color']) ?: '#CCCCCC';
        if (empty($nom)) {
            $feedback = ['type' => 'error', 'message' => 'Le nom de la zone est requis.'];
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO ambu_zones (nom, color, ordre) VALUES (?, ?, (SELECT COALESCE(MAX(ordre), 0) + 1 FROM ambu_zones z))");
                $stmt->execute([$nom, $color]);
                log_event($pdo, $_SESSION['username'], "[AMBU] Ajout zone: $nom");
                $feedback = ['type' => 'success', 'message' => 'Zone ajoutée avec succès.'];
                $activeTab = 'ambulance';
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }
    
    if (isset($_POST['edit_ambu_zone'])) {
        $id = (int)$_POST['zone_id'];
        $nom = trim($_POST['zone_nom']);
        $color = trim($_POST['zone_color']) ?: '#CCCCCC';
        if (empty($nom)) {
            $feedback = ['type' => 'error', 'message' => 'Le nom de la zone est requis.'];
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE ambu_zones SET nom = ?, color = ? WHERE id = ?");
                $stmt->execute([$nom, $color, $id]);
                log_event($pdo, $_SESSION['username'], "[AMBU] Modification zone ID $id: $nom");
                $feedback = ['type' => 'success', 'message' => 'Zone modifiée avec succès.'];
                $activeTab = 'ambulance';
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }
    
    if (isset($_POST['delete_ambu_zone'])) {
        $id = (int)$_POST['zone_id'];
        try {
            $stmt = $pdo->prepare("SELECT nom FROM ambu_zones WHERE id = ?");
            $stmt->execute([$id]);
            $nom = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM ambu_zones WHERE id = ?");
            $stmt->execute([$id]);
            log_event($pdo, $_SESSION['username'], "[AMBU] Suppression zone ID $id: $nom");
            $feedback = ['type' => 'success', 'message' => 'Zone supprimée avec succès.'];
            $activeTab = 'ambulance';
        } catch (PDOException $e) {
            $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
        }
    }
    
    // ========== AMBULANCE: ITEMS ==========
    
    if (isset($_POST['add_ambu_item'])) {
        $nom = trim($_POST['item_nom']);
        $quantite = trim($_POST['item_quantite']);
        $zone_id = (int)$_POST['zone_id'];
        $sous_zone_nom = trim($_POST['sous_zone_nom']) ?: null;
        
        if (empty($nom) || empty($zone_id)) {
            $feedback = ['type' => 'error', 'message' => 'Le nom et la zone sont requis.'];
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO ambu_items (nom, quantite, zone_id, sous_zone_nom, ordre) VALUES (?, ?, ?, ?, (SELECT COALESCE(MAX(ordre), 0) + 1 FROM ambu_items i WHERE i.zone_id = ?))");
                $stmt->execute([$nom, $quantite, $zone_id, $sous_zone_nom, $zone_id]);
                log_event($pdo, $_SESSION['username'], "[AMBU] Ajout item: $nom (Zone ID $zone_id, Sous-zone: " . ($sous_zone_nom ?: 'aucune') . ")");
                $feedback = ['type' => 'success', 'message' => 'Item ajouté avec succès.'];
                $activeTab = 'ambulance';
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }
    
    if (isset($_POST['edit_ambu_item'])) {
        $id = (int)$_POST['item_id'];
        $nom = trim($_POST['item_nom']);
        $quantite = trim($_POST['item_quantite']);
        $zone_id = (int)$_POST['zone_id'];
        $sous_zone_nom = trim($_POST['sous_zone_nom']) ?: null;
        
        if (empty($nom) || empty($zone_id)) {
            $feedback = ['type' => 'error', 'message' => 'Le nom et la zone sont requis.'];
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE ambu_items SET nom = ?, quantite = ?, zone_id = ?, sous_zone_nom = ? WHERE id = ?");
                $stmt->execute([$nom, $quantite, $zone_id, $sous_zone_nom, $id]);
                log_event($pdo, $_SESSION['username'], "[AMBU] Modification item ID $id: $nom");
                $feedback = ['type' => 'success', 'message' => 'Item modifié avec succès.'];
                $activeTab = 'ambulance';
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }
    
    if (isset($_POST['delete_ambu_item'])) {
        $id = (int)$_POST['item_id'];
        try {
            $stmt = $pdo->prepare("SELECT nom FROM ambu_items WHERE id = ?");
            $stmt->execute([$id]);
            $nom = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM ambu_items WHERE id = ?");
            $stmt->execute([$id]);
            log_event($pdo, $_SESSION['username'], "[AMBU] Suppression item ID $id: $nom");
            $feedback = ['type' => 'success', 'message' => 'Item supprimé avec succès.'];
            $activeTab = 'ambulance';
        } catch (PDOException $e) {
            $feedback = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
        }
    }
}

// ========== RÉCUPÉRATION DES DONNÉES ==========

// DPS
$pochettes = $pdo->query("SELECT * FROM pochettes ORDER BY ordre, id")->fetchAll(PDO::FETCH_ASSOC);
$produits = $pdo->query("SELECT p.*, po.nom AS pochette_nom FROM produits p JOIN pochettes po ON p.pochette_id = po.id ORDER BY po.ordre, p.ordre")->fetchAll(PDO::FETCH_ASSOC);

// Ambulance
$ambu_zones = $pdo->query("SELECT * FROM ambu_zones ORDER BY ordre, id")->fetchAll(PDO::FETCH_ASSOC);
$ambu_items = $pdo->query("SELECT i.*, z.nom AS zone_nom FROM ambu_items i JOIN ambu_zones z ON i.zone_id = z.id ORDER BY z.ordre, i.sous_zone_nom, i.nom")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Structures - Admin</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Styles spécifiques pour les onglets */
        .tabs-container {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
        }
        .tab-button {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--text-secondary);
        }
        .tab-button:hover {
            color: var(--color-primary);
            background: var(--bg-pochette);
        }
        .tab-button.active {
            color: var(--color-primary);
            border-bottom-color: var(--color-primary);
            font-weight: 600;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        
        /* Drag and drop */
        .sortable-list {
            list-style: none;
            padding: 0;
        }
        .sortable-item {
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: var(--bg-container);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            cursor: move;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        .sortable-item:hover {
            box-shadow: var(--shadow-hover);
        }
        .sortable-item.dragging {
            opacity: 0.5;
        }
        
        /* Color picker */
        .color-picker-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .color-preview {
            width: 30px;
            height: 30px;
            border-radius: 5px;
            border: 1px solid var(--border-color);
        }
        
        /* Autocomplete */
        .autocomplete-wrapper {
            position: relative;
        }
        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--bg-container);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        .autocomplete-suggestions.show {
            display: block;
        }
        .autocomplete-item {
            padding: 0.75rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .autocomplete-item:hover {
            background: var(--bg-pochette);
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <h1 class="title">📋 Gestion des Structures</h1>
                <div class="header-actions">
                    <a href="admin.php" class="btn btn-secondary">← Retour Admin</a>
                </div>
            </div>
        </header>

        <main class="main-content">
            <?php if ($feedback['message']): ?>
                <div class="alert alert-<?= $feedback['type'] ?>">
                    <?= htmlspecialchars($feedback['message']) ?>
                </div>
            <?php endif; ?>

            <!-- ONGLETS -->
            <div class="tabs-container">
                <button class="tab-button <?= $activeTab === 'dps' ? 'active' : '' ?>" onclick="switchTab('dps')">
                    🎒 Sacs DPS
                </button>
                <button class="tab-button <?= $activeTab === 'ambulance' ? 'active' : '' ?>" onclick="switchTab('ambulance')">
                    🚑 Ambulance
                </button>
            </div>

            <!-- CONTENU DPS -->
            <div id="tab-dps" class="tab-content <?= $activeTab === 'dps' ? 'active' : '' ?>">
                
                <!-- SECTION POCHETTES -->
                <section class="admin-section">
                    <h2>📦 Gestion des Pochettes</h2>
                    <p class="section-description">Organisez les pochettes par drag-and-drop. Les produits suivront automatiquement.</p>
                    
                    <ul id="pochettes-sortable" class="sortable-list">
                        <?php foreach ($pochettes as $pochette): ?>
                            <li class="sortable-item" data-id="<?= $pochette['id'] ?>">
                                <div style="display: flex; align-items: center; gap: 1rem;">
                                    <span class="drag-handle" style="cursor: move;">⠿</span>
                                    <div class="color-preview" style="background-color: <?= htmlspecialchars($pochette['color']) ?>;"></div>
                                    <strong><?= htmlspecialchars($pochette['nom']) ?></strong>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    <button onclick="editPochette(<?= $pochette['id'] ?>, '<?= htmlspecialchars($pochette['nom'], ENT_QUOTES) ?>', '<?= htmlspecialchars($pochette['color']) ?>')" class="btn btn-sm btn-primary">Modifier</button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Supprimer cette pochette et TOUS ses produits ?');">
                                        <input type="hidden" name="pochette_id" value="<?= $pochette['id'] ?>">
                                        <button type="submit" name="delete_pochette" class="btn btn-sm btn-danger">Supprimer</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <!-- Formulaire d'ajout/modification pochette -->
                    <form method="POST" id="form-pochette" class="admin-form" style="margin-top: 2rem;">
                        <input type="hidden" id="pochette_id" name="pochette_id">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="pochette_nom">Nom de la pochette *</label>
                                <input type="text" id="pochette_nom" name="pochette_nom" required>
                            </div>
                            <div class="form-group">
                                <label for="pochette_color">Couleur</label>
                                <div class="color-picker-wrapper">
                                    <input type="color" id="pochette_color" name="pochette_color" value="#FFFFFF">
                                    <span class="color-preview" id="pochette_color_preview" style="background: #FFFFFF;"></span>
                                </div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="add_pochette" id="btn-add-pochette" class="btn btn-primary">Ajouter une pochette</button>
                            <button type="submit" name="edit_pochette" id="btn-edit-pochette" class="btn btn-primary" style="display: none;">Modifier la pochette</button>
                            <button type="button" onclick="resetPochetteForm()" class="btn btn-secondary">Annuler</button>
                        </div>
                    </form>
                </section>

                <!-- SECTION PRODUITS -->
                <section class="admin-section" style="margin-top: 3rem;">
                    <h2>📦 Gestion des Produits</h2>
                    
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Quantité</th>
                                <th>Pochette</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($produits as $produit): ?>
                                <tr>
                                    <td><?= htmlspecialchars($produit['nom']) ?></td>
                                    <td><?= htmlspecialchars($produit['quantite']) ?></td>
                                    <td><?= htmlspecialchars($produit['pochette_nom']) ?></td>
                                    <td>
                                        <button onclick="editProduit(<?= $produit['id'] ?>, '<?= htmlspecialchars($produit['nom'], ENT_QUOTES) ?>', '<?= htmlspecialchars($produit['quantite'], ENT_QUOTES) ?>', <?= $produit['pochette_id'] ?>)" class="btn btn-sm btn-primary">Modifier</button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Supprimer ce produit ?');">
                                            <input type="hidden" name="produit_id" value="<?= $produit['id'] ?>">
                                            <button type="submit" name="delete_produit" class="btn btn-sm btn-danger">Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Formulaire d'ajout/modification produit -->
                    <form method="POST" id="form-produit" class="admin-form" style="margin-top: 2rem;">
                        <input type="hidden" id="produit_id" name="produit_id">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="produit_nom">Nom du produit *</label>
                                <input type="text" id="produit_nom" name="produit_nom" required>
                            </div>
                            <div class="form-group">
                                <label for="produit_quantite">Quantité</label>
                                <input type="text" id="produit_quantite" name="produit_quantite">
                            </div>
                            <div class="form-group">
                                <label for="produit_pochette_id">Pochette *</label>
                                <select id="produit_pochette_id" name="pochette_id" required>
                                    <option value="">-- Sélectionner --</option>
                                    <?php foreach ($pochettes as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="add_produit" id="btn-add-produit" class="btn btn-primary">Ajouter un produit</button>
                            <button type="submit" name="edit_produit" id="btn-edit-produit" class="btn btn-primary" style="display: none;">Modifier le produit</button>
                            <button type="button" onclick="resetProduitForm()" class="btn btn-secondary">Annuler</button>
                        </div>
                    </form>
                </section>

            </div>

            <!-- CONTENU AMBULANCE -->
            <div id="tab-ambulance" class="tab-content <?= $activeTab === 'ambulance' ? 'active' : '' ?>">
                
                <!-- SECTION ZONES -->
                <section class="admin-section">
                    <h2>🚑 Gestion des Zones</h2>
                    <p class="section-description">Organisez les zones par drag-and-drop.</p>
                    
                    <ul id="zones-sortable" class="sortable-list">
                        <?php foreach ($ambu_zones as $zone): ?>
                            <li class="sortable-item" data-id="<?= $zone['id'] ?>">
                                <div style="display: flex; align-items: center; gap: 1rem;">
                                    <span class="drag-handle" style="cursor: move;">⠿</span>
                                    <div class="color-preview" style="background-color: <?= htmlspecialchars($zone['color']) ?>;"></div>
                                    <strong><?= htmlspecialchars($zone['nom']) ?></strong>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    <button onclick="editZone(<?= $zone['id'] ?>, '<?= htmlspecialchars($zone['nom'], ENT_QUOTES) ?>', '<?= htmlspecialchars($zone['color']) ?>')" class="btn btn-sm btn-primary">Modifier</button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Supprimer cette zone et TOUS ses items ?');">
                                        <input type="hidden" name="zone_id" value="<?= $zone['id'] ?>">
                                        <button type="submit" name="delete_ambu_zone" class="btn btn-sm btn-danger">Supprimer</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <!-- Formulaire d'ajout/modification zone -->
                    <form method="POST" id="form-zone" class="admin-form" style="margin-top: 2rem;">
                        <input type="hidden" id="zone_id" name="zone_id">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="zone_nom">Nom de la zone *</label>
                                <input type="text" id="zone_nom" name="zone_nom" required>
                            </div>
                            <div class="form-group">
                                <label for="zone_color">Couleur</label>
                                <div class="color-picker-wrapper">
                                    <input type="color" id="zone_color" name="zone_color" value="#CCCCCC">
                                    <span class="color-preview" id="zone_color_preview" style="background: #CCCCCC;"></span>
                                </div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="add_ambu_zone" id="btn-add-zone" class="btn btn-primary">Ajouter une zone</button>
                            <button type="submit" name="edit_ambu_zone" id="btn-edit-zone" class="btn btn-primary" style="display: none;">Modifier la zone</button>
                            <button type="button" onclick="resetZoneForm()" class="btn btn-secondary">Annuler</button>
                        </div>
                    </form>
                </section>

                <!-- SECTION ITEMS -->
                <section class="admin-section" style="margin-top: 3rem;">
                    <h2>📦 Gestion des Items</h2>
                    
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Quantité</th>
                                <th>Zone</th>
                                <th>Sous-zone</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ambu_items as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['nom']) ?></td>
                                    <td><?= htmlspecialchars($item['quantite']) ?></td>
                                    <td><?= htmlspecialchars($item['zone_nom']) ?></td>
                                    <td><?= htmlspecialchars($item['sous_zone_nom'] ?: '(direct)') ?></td>
                                    <td>
                                        <button onclick="editItem(<?= $item['id'] ?>, '<?= htmlspecialchars($item['nom'], ENT_QUOTES) ?>', '<?= htmlspecialchars($item['quantite'], ENT_QUOTES) ?>', <?= $item['zone_id'] ?>, '<?= htmlspecialchars($item['sous_zone_nom'] ?? '', ENT_QUOTES) ?>')" class="btn btn-sm btn-primary">Modifier</button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Supprimer cet item ?');">
                                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                            <button type="submit" name="delete_ambu_item" class="btn btn-sm btn-danger">Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Formulaire d'ajout/modification item -->
                    <form method="POST" id="form-item" class="admin-form" style="margin-top: 2rem;">
                        <input type="hidden" id="item_id" name="item_id">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="item_nom">Nom de l'item *</label>
                                <input type="text" id="item_nom" name="item_nom" required>
                            </div>
                            <div class="form-group">
                                <label for="item_quantite">Quantité</label>
                                <input type="text" id="item_quantite" name="item_quantite">
                            </div>
                            <div class="form-group">
                                <label for="item_zone_id">Zone *</label>
                                <select id="item_zone_id" name="zone_id" required>
                                    <option value="">-- Sélectionner --</option>
                                    <?php foreach ($ambu_zones as $z): ?>
                                        <option value="<?= $z['id'] ?>"><?= htmlspecialchars($z['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group autocomplete-wrapper">
                                <label for="item_sous_zone">Sous-zone (optionnel)</label>
                                <input type="text" id="item_sous_zone" name="sous_zone_nom" autocomplete="off" placeholder="Laisser vide pour item direct">
                                <div id="autocomplete-suggestions" class="autocomplete-suggestions"></div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="add_ambu_item" id="btn-add-item" class="btn btn-primary">Ajouter un item</button>
                            <button type="submit" name="edit_ambu_item" id="btn-edit-item" class="btn btn-primary" style="display: none;">Modifier l'item</button>
                            <button type="button" onclick="resetItemForm()" class="btn btn-secondary">Annuler</button>
                        </div>
                    </form>
                </section>

            </div>
        </main>
    </div>

    <script>
        // ========== GESTION DES ONGLETS ==========
        function switchTab(tab) {
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            document.querySelector(`[onclick="switchTab('${tab}')"]`).classList.add('active');
            document.getElementById(`tab-${tab}`).classList.add('active');
            
            // Mettre à jour l'URL sans recharger
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.pushState({}, '', url);
        }

        // ========== DPS: POCHETTES ==========
        document.getElementById('pochette_color').addEventListener('input', (e) => {
            document.getElementById('pochette_color_preview').style.background = e.target.value;
        });

        function editPochette(id, nom, color) {
            document.getElementById('pochette_id').value = id;
            document.getElementById('pochette_nom').value = nom;
            document.getElementById('pochette_color').value = color;
            document.getElementById('pochette_color_preview').style.background = color;
            document.getElementById('btn-add-pochette').style.display = 'none';
            document.getElementById('btn-edit-pochette').style.display = 'inline-block';
            document.getElementById('pochette_nom').focus();
        }

        function resetPochetteForm() {
            document.getElementById('form-pochette').reset();
            document.getElementById('pochette_id').value = '';
            document.getElementById('pochette_color_preview').style.background = '#FFFFFF';
            document.getElementById('btn-add-pochette').style.display = 'inline-block';
            document.getElementById('btn-edit-pochette').style.display = 'none';
        }

        // Drag-and-drop pochettes
        const pochettesList = document.getElementById('pochettes-sortable');
        let draggedPochette = null;

        pochettesList.addEventListener('dragstart', (e) => {
            if (e.target.classList.contains('sortable-item')) {
                draggedPochette = e.target;
                e.target.classList.add('dragging');
            }
        });

        pochettesList.addEventListener('dragend', (e) => {
            if (e.target.classList.contains('sortable-item')) {
                e.target.classList.remove('dragging');
                savePochettesOrder();
            }
        });

        pochettesList.addEventListener('dragover', (e) => {
            e.preventDefault();
            const afterElement = getDragAfterElement(pochettesList, e.clientY);
            if (afterElement == null) {
                pochettesList.appendChild(draggedPochette);
            } else {
                pochettesList.insertBefore(draggedPochette, afterElement);
            }
        });

        function savePochettesOrder() {
            const order = Array.from(pochettesList.children).map(item => item.dataset.id);
            fetch('admin_structure.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `update_pochette_order=1&order=${JSON.stringify(order)}`
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    console.log('Ordre sauvegardé');
                }
            });
        }

        // ========== DPS: PRODUITS ==========
        function editProduit(id, nom, quantite, pochette_id) {
            document.getElementById('produit_id').value = id;
            document.getElementById('produit_nom').value = nom;
            document.getElementById('produit_quantite').value = quantite;
            document.getElementById('produit_pochette_id').value = pochette_id;
            document.getElementById('btn-add-produit').style.display = 'none';
            document.getElementById('btn-edit-produit').style.display = 'inline-block';
            document.getElementById('produit_nom').focus();
        }

        function resetProduitForm() {
            document.getElementById('form-produit').reset();
            document.getElementById('produit_id').value = '';
            document.getElementById('btn-add-produit').style.display = 'inline-block';
            document.getElementById('btn-edit-produit').style.display = 'none';
        }

        // ========== AMBULANCE: ZONES ==========
        document.getElementById('zone_color').addEventListener('input', (e) => {
            document.getElementById('zone_color_preview').style.background = e.target.value;
        });

        function editZone(id, nom, color) {
            document.getElementById('zone_id').value = id;
            document.getElementById('zone_nom').value = nom;
            document.getElementById('zone_color').value = color;
            document.getElementById('zone_color_preview').style.background = color;
            document.getElementById('btn-add-zone').style.display = 'none';
            document.getElementById('btn-edit-zone').style.display = 'inline-block';
            document.getElementById('zone_nom').focus();
        }

        function resetZoneForm() {
            document.getElementById('form-zone').reset();
            document.getElementById('zone_id').value = '';
            document.getElementById('zone_color_preview').style.background = '#CCCCCC';
            document.getElementById('btn-add-zone').style.display = 'inline-block';
            document.getElementById('btn-edit-zone').style.display = 'none';
        }

        // Drag-and-drop zones
        const zonesList = document.getElementById('zones-sortable');
        let draggedZone = null;

        zonesList.addEventListener('dragstart', (e) => {
            if (e.target.classList.contains('sortable-item')) {
                draggedZone = e.target;
                e.target.classList.add('dragging');
            }
        });

        zonesList.addEventListener('dragend', (e) => {
            if (e.target.classList.contains('sortable-item')) {
                e.target.classList.remove('dragging');
                saveZonesOrder();
            }
        });

        zonesList.addEventListener('dragover', (e) => {
            e.preventDefault();
            const afterElement = getDragAfterElement(zonesList, e.clientY);
            if (afterElement == null) {
                zonesList.appendChild(draggedZone);
            } else {
                zonesList.insertBefore(draggedZone, afterElement);
            }
        });

        function saveZonesOrder() {
            const order = Array.from(zonesList.children).map(item => item.dataset.id);
            fetch('admin_structure.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `update_ambu_zone_order=1&order=${JSON.stringify(order)}`
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    console.log('Ordre sauvegardé');
                }
            });
        }

        // ========== AMBULANCE: ITEMS + AUTOCOMPLETE ==========
        let sousZones = [];
        
        // Charger les sous-zones existantes
        fetch('admin_structure.php?get_sous_zones=1')
            .then(r => r.json())
            .then(data => { sousZones = data; });

        const sousZoneInput = document.getElementById('item_sous_zone');
        const suggestions = document.getElementById('autocomplete-suggestions');

        sousZoneInput.addEventListener('input', (e) => {
            const value = e.target.value.toLowerCase();
            if (value.length === 0) {
                suggestions.classList.remove('show');
                return;
            }
            
            const filtered = sousZones.filter(sz => sz.toLowerCase().includes(value));
            if (filtered.length === 0) {
                suggestions.classList.remove('show');
                return;
            }
            
            suggestions.innerHTML = filtered.map(sz => 
                `<div class="autocomplete-item" onclick="selectSousZone('${sz}')">${sz}</div>`
            ).join('');
            suggestions.classList.add('show');
        });

        function selectSousZone(value) {
            sousZoneInput.value = value;
            suggestions.classList.remove('show');
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.autocomplete-wrapper')) {
                suggestions.classList.remove('show');
            }
        });

        function editItem(id, nom, quantite, zone_id, sous_zone) {
            document.getElementById('item_id').value = id;
            document.getElementById('item_nom').value = nom;
            document.getElementById('item_quantite').value = quantite;
            document.getElementById('item_zone_id').value = zone_id;
            document.getElementById('item_sous_zone').value = sous_zone || '';
            document.getElementById('btn-add-item').style.display = 'none';
            document.getElementById('btn-edit-item').style.display = 'inline-block';
            document.getElementById('item_nom').focus();
        }

        function resetItemForm() {
            document.getElementById('form-item').reset();
            document.getElementById('item_id').value = '';
            document.getElementById('btn-add-item').style.display = 'inline-block';
            document.getElementById('btn-edit-item').style.display = 'none';
        }

        // ========== UTILITAIRE DRAG-AND-DROP ==========
        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.sortable-item:not(.dragging)')];
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        // Rendre les items draggables
        document.querySelectorAll('.sortable-item').forEach(item => {
            item.setAttribute('draggable', 'true');
        });
    </script>
</body>
</html>

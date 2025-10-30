<?php
// =====================================================
// admin_checklist.php - MODIFIÉ V4.3 (AJAX Séparé)
// - SUPPRESSION: Handler AJAX list_sous_zones déplacé vers ajax_ambu.php
// - MODIFICATION: Appel fetch() JS pointe vers ajax_ambu.php
// =====================================================

require_once 'access_control.php';
require_login('editor'); // Rôle 'editor' au minimum
require_once 'config.php'; // Pour UPLOAD_PATH
require_once 'functions.php'; // Pour sanitize_input et log_event

$feedback = ['type' => '', 'message' => ''];
$pdo = $GLOBALS['pdo']; // Récupérer le $pdo global
$active_tab = 'dps'; // Onglet par défaut

// =====================================================
// Handlers AJAX D&D (Restent ici)
// =====================================================

// --- Drag-and-Drop Pochettes DPS ---
if (isset($_POST['update_pochette_order'])) {
    header('Content-Type: application/json; charset=utf-8');
    $order = json_decode($_POST['order'] ?? '[]', true);
    if (is_array($order) && !empty($order)) {
        try { $pdo->beginTransaction(); foreach ($order as $index => $id) { $stmt = $pdo->prepare("UPDATE pochettes SET ordre = ? WHERE id = ?"); $stmt->execute([$index + 1, (int)$id]); } $pdo->commit(); log_event($pdo, $_SESSION['username'], "[DPS] Réorganisation (drag-and-drop) des pochettes."); echo json_encode(['success' => true, 'message' => 'Ordre sauvegardé.'], JSON_UNESCAPED_UNICODE); exit;
        } catch (Exception $e) { $pdo->rollBack(); error_log("Erreur D&D Pochettes DPS: ".$e->getMessage()); echo json_encode(['success' => false, 'message' => 'Erreur serveur.'], JSON_UNESCAPED_UNICODE); exit; }
    } echo json_encode(['success' => false, 'message' => 'Données invalides.'], JSON_UNESCAPED_UNICODE); exit;
}

// --- Drag-and-Drop Zones AMBU ---
if (isset($_POST['update_ambu_zone_order'])) {
     header('Content-Type: application/json; charset=utf-8');
    $order = json_decode($_POST['order'] ?? '[]', true);
    if (is_array($order) && !empty($order)) {
        try { $pdo->beginTransaction(); foreach ($order as $index => $id) { $stmt = $pdo->prepare("UPDATE ambu_zones SET ordre = ? WHERE id = ?"); $stmt->execute([$index + 1, (int)$id]); } $pdo->commit(); log_event($pdo, $_SESSION['username'], "[AMBU] Réorganisation (drag-and-drop) des zones."); echo json_encode(['success' => true, 'message' => 'Ordre sauvegardé.'], JSON_UNESCAPED_UNICODE); exit;
        } catch (Exception $e) { $pdo->rollBack(); error_log("Erreur D&D Zones AMBU: ".$e->getMessage()); echo json_encode(['success' => false, 'message' => 'Erreur serveur.'], JSON_UNESCAPED_UNICODE); exit; }
    } echo json_encode(['success' => false, 'message' => 'Données invalides.'], JSON_UNESCAPED_UNICODE); exit;
}

// --- Drag-and-Drop Sous-Zones AMBU ---
if (isset($_POST['update_ambu_sous_zone_order'])) {
    header('Content-Type: application/json; charset=utf-8');
    $order = json_decode($_POST['order'] ?? '[]', true); $zone_id = isset($_POST['zone_id']) ? (int)$_POST['zone_id'] : 0;
    if (is_array($order) && !empty($order) && $zone_id > 0) {
        try { $pdo->beginTransaction(); foreach ($order as $index => $id) { $stmt = $pdo->prepare("UPDATE ambu_sous_zones SET ordre = ? WHERE id = ? AND zone_id = ?"); $stmt->execute([$index + 1, (int)$id, $zone_id]); } $pdo->commit(); log_event($pdo, $_SESSION['username'], "[AMBU] Réorganisation (drag-and-drop) des sous-zones (Zone ID: $zone_id)."); echo json_encode(['success' => true, 'message' => 'Ordre sauvegardé.'], JSON_UNESCAPED_UNICODE); exit;
        } catch (Exception $e) { $pdo->rollBack(); error_log("Erreur D&D Sous-Zones AMBU: ".$e->getMessage()); echo json_encode(['success' => false, 'message' => 'Erreur serveur.'], JSON_UNESCAPED_UNICODE); exit; }
    } echo json_encode(['success' => false, 'message' => 'Données invalides (ordre ou zone_id).'], JSON_UNESCAPED_UNICODE); exit;
}

// --- Endpoint AJAX list_sous_zones a été DÉPLACÉ vers ajax_ambu.php ---

// =====================================================
// Traitement POST (Formulaires - Inchangé)
// =====================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (isset($_POST['active_tab'])) { $active_tab = $_POST['active_tab'] === 'ambu' ? 'ambu' : 'dps'; }

    // --- GESTION DPS (SAC) ---
    if (isset($_POST['add_product'])) { $nom = sanitize_input($_POST['nom']); $quantite = sanitize_input($_POST['quantite']); $pochette_id = (int)$_POST['pochette_id']; $is_active = isset($_POST['is_active']) ? 1 : 0; if (!empty($nom) && !empty($pochette_id)) { try { $stmt_pochette = $pdo->prepare("SELECT nom, color FROM pochettes WHERE id = ?"); $stmt_pochette->execute([$pochette_id]); $pochette = $stmt_pochette->fetch(); if ($pochette) { $stmt = $pdo->prepare("INSERT INTO produits (nom, quantite, is_active, pochette_id, pochette, color, thumbnail, high_res) VALUES (?, ?, ?, ?, ?, ?, 'noimage.jpg', 'noimage_high.jpg')"); $stmt->execute([$nom, $quantite, $is_active, $pochette_id, $pochette['nom'], $pochette['color']]); log_event($pdo, $_SESSION['username'], "[DPS] Ajout item \"$nom\" (Actif: $is_active)."); $feedback = ['type' => 'success', 'message' => 'Produit DPS ajouté.']; } else { $feedback = ['type' => 'error', 'message' => 'Pochette DPS non valide.']; } } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'Nom et Pochette requis (DPS).']; } }
    if (isset($_POST['update_product'])) { $id = (int)$_POST['id']; $nom = sanitize_input($_POST['nom']); $quantite = sanitize_input($_POST['quantite']); $pochette_id = (int)$_POST['pochette_id']; $is_active = isset($_POST['is_active']) ? 1 : 0; if (!empty($nom) && !empty($pochette_id)) { try { $stmt_pochette = $pdo->prepare("SELECT nom, color FROM pochettes WHERE id = ?"); $stmt_pochette->execute([$pochette_id]); $pochette = $stmt_pochette->fetch(); if ($pochette) { $stmt = $pdo->prepare("UPDATE produits SET nom = ?, quantite = ?, is_active = ?, pochette_id = ?, pochette = ?, color = ? WHERE id = ?"); $stmt->execute([$nom, $quantite, $is_active, $pochette_id, $pochette['nom'], $pochette['color'], $id]); log_event($pdo, $_SESSION['username'], "[DPS] MàJ item \"$nom\" (ID: $id, Actif: $is_active)."); $feedback = ['type' => 'success', 'message' => 'Produit DPS mis à jour.']; } else { $feedback = ['type' => 'error', 'message' => 'Pochette DPS non valide.']; } } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'Nom et Pochette requis (DPS).']; } }
    if (isset($_POST['delete_product'])) { $id = (int)$_POST['id']; try { $stmt = $pdo->prepare("SELECT nom FROM produits WHERE id = ?"); $stmt->execute([$id]); $prod = $stmt->fetch(); if ($prod) { $stmt_delete = $pdo->prepare("UPDATE produits SET deleted_at = NOW() WHERE id = ?"); $stmt_delete->execute([$id]); log_event($pdo, $_SESSION['username'], "[DPS] Suppression (soft) item \"".($prod['nom'] ?? 'ID '.$id)."\" (ID: $id)."); $feedback = ['type' => 'success', 'message' => 'Produit DPS archivé.']; } } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } }
    if (isset($_POST['add_pochette'])) { $nom = sanitize_input($_POST['nom']); $color = sanitize_input($_POST['color']); $ordre = (int)$_POST['ordre']; if(!empty($nom)){ try { $stmt = $pdo->prepare("INSERT INTO pochettes (nom, color, ordre) VALUES (?, ?, ?)"); $stmt->execute([$nom, $color, $ordre]); log_event($pdo, $_SESSION['username'], "[DPS] Ajout pochette \"$nom\"."); $feedback = ['type' => 'success', 'message' => 'Pochette DPS ajoutée.']; } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'Nom de pochette requis.']; } }
    if (isset($_POST['update_pochette'])) { $id = (int)$_POST['pochette_id']; $nom = sanitize_input($_POST['nom']); $color = sanitize_input($_POST['color']); $ordre = (int)$_POST['ordre']; if(!empty($nom) && $id > 0){ try { $pdo->beginTransaction(); $stmt = $pdo->prepare("UPDATE pochettes SET nom = ?, color = ?, ordre = ? WHERE id = ?"); $stmt->execute([$nom, $color, $ordre, $id]); $stmt_update_prods = $pdo->prepare("UPDATE produits SET pochette = ?, color = ? WHERE pochette_id = ?"); $stmt_update_prods->execute([$nom, $color, $id]); $pdo->commit(); log_event($pdo, $_SESSION['username'], "[DPS] Modification pochette \"$nom\" (ID: $id)."); $feedback = ['type' => 'success', 'message' => 'Pochette DPS mise à jour.']; } catch (PDOException $e) { $pdo->rollBack(); $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'Nom de pochette requis.']; } }
    if (isset($_POST['delete_pochette'])) { $id = (int)$_POST['pochette_id']; if($id>0){ try { $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM produits WHERE pochette_id = ? AND deleted_at IS NULL"); $stmt_check->execute([$id]); $count = $stmt_check->fetchColumn(); if ($count > 0) { $feedback = ['type' => 'error', 'message' => "Impossible: $count produit(s) DPS actifs y sont liés."]; } else { $stmt_pochette = $pdo->prepare("SELECT nom FROM pochettes WHERE id = ?"); $stmt_pochette->execute([$id]); $pochette = $stmt_pochette->fetch(); $stmt = $pdo->prepare("DELETE FROM pochettes WHERE id = ?"); $stmt->execute([$id]); log_event($pdo, $_SESSION['username'], "[DPS] Suppression pochette \"".($pochette['nom'] ?? 'ID '.$id)."\" (ID: $id)."); $feedback = ['type' => 'success', 'message' => 'Pochette DPS supprimée.']; } } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'ID Pochette invalide.']; } }

    // --- GESTION AMBULANCE ---
    if (isset($_POST['add_ambu_zone'])) { $nom = sanitize_input($_POST['nom']); $color = sanitize_input($_POST['color']); $ordre = (int)$_POST['ordre']; if(!empty($nom)){ try { $stmt = $pdo->prepare("INSERT INTO ambu_zones (nom, color, ordre) VALUES (?, ?, ?)"); $stmt->execute([$nom, $color, $ordre]); log_event($pdo, $_SESSION['username'], "[AMBU] Ajout zone \"$nom\"."); $feedback = ['type' => 'success', 'message' => 'Zone Ambu ajoutée.']; } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'Nom de zone requis.']; } }
    if (isset($_POST['update_ambu_zone'])) { $id = (int)$_POST['ambu_zone_id']; $nom = sanitize_input($_POST['nom']); $color = sanitize_input($_POST['color']); $ordre = (int)$_POST['ordre']; if(!empty($nom) && $id > 0){ try { $stmt = $pdo->prepare("UPDATE ambu_zones SET nom = ?, color = ?, ordre = ? WHERE id = ?"); $stmt->execute([$nom, $color, $ordre, $id]); log_event($pdo, $_SESSION['username'], "[AMBU] Modification zone \"$nom\" (ID: $id)."); $feedback = ['type' => 'success', 'message' => 'Zone Ambu mise à jour.']; } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'Nom de zone requis.']; } }
    if (isset($_POST['delete_ambu_zone'])) { $id = (int)$_POST['ambu_zone_id']; if($id>0){ try { $stmt_zone = $pdo->prepare("SELECT nom FROM ambu_zones WHERE id = ?"); $stmt_zone->execute([$id]); $zone = $stmt_zone->fetch(); $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM ambu_items WHERE zone_id = ? AND is_active = 1"); $stmt_check->execute([$id]); $count = $stmt_check->fetchColumn(); if ($count > 0) { $feedback = ['type' => 'error', 'message' => "Impossible: $count item(s) Ambu ACTIFS y sont liés."]; } else { $stmt = $pdo->prepare("DELETE FROM ambu_zones WHERE id = ?"); $stmt->execute([$id]); log_event($pdo, $_SESSION['username'], "[AMBU] Suppression zone \"".($zone['nom'] ?? 'ID '.$id)."\" (ID: $id)."); $feedback = ['type' => 'success', 'message' => 'Zone Ambu (et ses sous-zones) supprimée.']; } } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'ID Zone invalide.']; } }
    if (isset($_POST['add_ambu_sous_zone'])) { $nom = sanitize_input($_POST['nom']); $color = sanitize_input($_POST['color']); $ordre = (int)$_POST['ordre']; $zone_id = (int)$_POST['zone_id']; if (!empty($nom) && $zone_id > 0) { try { $stmt = $pdo->prepare("INSERT INTO ambu_sous_zones (zone_id, nom, color, ordre) VALUES (?, ?, ?, ?)"); $stmt->execute([$zone_id, $nom, $color, $ordre]); log_event($pdo, $_SESSION['username'], "[AMBU] Ajout sous-zone \"$nom\" (Zone ID: $zone_id)."); $feedback = ['type' => 'success', 'message' => 'Sous-Zone Ambu ajoutée.']; } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'Nom et Zone parente requis.']; } }
    if (isset($_POST['update_ambu_sous_zone'])) { $id = (int)$_POST['ambu_sous_zone_id']; $nom = sanitize_input($_POST['nom']); $color = sanitize_input($_POST['color']); $ordre = (int)$_POST['ordre']; $zone_id = (int)$_POST['zone_id']; if (!empty($nom) && $zone_id > 0 && $id > 0) { try { $pdo->beginTransaction(); $stmt_old_name = $pdo->prepare("SELECT nom FROM ambu_sous_zones WHERE id = ? AND zone_id = ?"); $stmt_old_name->execute([$id, $zone_id]); $old_name = $stmt_old_name->fetchColumn(); $stmt = $pdo->prepare("UPDATE ambu_sous_zones SET nom = ?, color = ?, ordre = ? WHERE id = ? AND zone_id = ?"); $stmt->execute([$nom, $color, $ordre, $id, $zone_id]); if ($old_name !== false && $old_name !== $nom) { $stmt_update_items = $pdo->prepare("UPDATE ambu_items SET sous_zone_nom = ? WHERE sous_zone_nom = ? AND zone_id = ?"); $stmt_update_items->execute([$nom, $old_name, $zone_id]); } $pdo->commit(); log_event($pdo, $_SESSION['username'], "[AMBU] Modification sous-zone \"$nom\" (ID: $id, Zone ID: $zone_id)."); $feedback = ['type' => 'success', 'message' => 'Sous-Zone Ambu mise à jour.']; } catch (PDOException $e) { $pdo->rollBack(); $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'Données invalides pour la modification de sous-zone.']; } }
    if (isset($_POST['delete_ambu_sous_zone'])) { $id = (int)$_POST['ambu_sous_zone_id']; if ($id > 0) { try { $stmt_sz = $pdo->prepare("SELECT nom, zone_id FROM ambu_sous_zones WHERE id = ?"); $stmt_sz->execute([$id]); $sz = $stmt_sz->fetch(); if ($sz) { $pdo->beginTransaction(); $stmt_unlink = $pdo->prepare("UPDATE ambu_items SET sous_zone_nom = NULL WHERE sous_zone_nom = ? AND zone_id = ?"); $stmt_unlink->execute([$sz['nom'], $sz['zone_id']]); $stmt_delete = $pdo->prepare("DELETE FROM ambu_sous_zones WHERE id = ?"); $stmt_delete->execute([$id]); $pdo->commit(); log_event($pdo, $_SESSION['username'], "[AMBU] Suppression sous-zone \"".($sz['nom'] ?? 'ID '.$id)."\" (ID: $id, Zone ID: ".$sz['zone_id'].")."); $feedback = ['type' => 'success', 'message' => 'Sous-Zone Ambu supprimée. Items déliés.']; } else { $feedback = ['type' => 'error', 'message' => 'Sous-zone non trouvée.']; } } catch (PDOException $e) { $pdo->rollBack(); $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'ID Sous-zone invalide.']; } }
    if (isset($_POST['add_ambu_item'])) { $nom = sanitize_input($_POST['nom']); $quantite = sanitize_input($_POST['quantite']); $zone_id = (int)$_POST['zone_id']; $ordre = (int)$_POST['ordre']; $is_active = isset($_POST['is_active']) ? 1 : 0; $sous_zone_nom = !empty($_POST['sous_zone_nom']) ? sanitize_input($_POST['sous_zone_nom']) : null; if (!empty($nom) && $zone_id > 0) { try { $stmt = $pdo->prepare("INSERT INTO ambu_items (nom, quantite, is_active, zone_id, sous_zone_nom, ordre, thumbnail, high_res) VALUES (?, ?, ?, ?, ?, ?, 'noimage.jpg', 'noimage_high.jpg')"); $stmt->execute([$nom, $quantite, $is_active, $zone_id, $sous_zone_nom, $ordre]); log_event($pdo, $_SESSION['username'], "[AMBU] Ajout item \"$nom\" (Zone ID: $zone_id, Sous-zone: ".($sous_zone_nom ?? 'aucune').", Actif: $is_active)."); $feedback = ['type' => 'success', 'message' => 'Item Ambu ajouté.']; } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'Nom et Zone requis (Ambu).']; } }
    if (isset($_POST['update_ambu_item'])) { $id = (int)$_POST['id']; $nom = sanitize_input($_POST['nom']); $quantite = sanitize_input($_POST['quantite']); $zone_id = (int)$_POST['zone_id']; $ordre = (int)$_POST['ordre']; $is_active = isset($_POST['is_active']) ? 1 : 0; $sous_zone_nom = !empty($_POST['sous_zone_nom']) ? sanitize_input($_POST['sous_zone_nom']) : null; if (!empty($nom) && $zone_id > 0 && $id > 0) { try { $stmt = $pdo->prepare("UPDATE ambu_items SET nom = ?, quantite = ?, is_active = ?, zone_id = ?, sous_zone_nom = ?, ordre = ? WHERE id = ?"); $stmt->execute([$nom, $quantite, $is_active, $zone_id, $sous_zone_nom, $ordre, $id]); log_event($pdo, $_SESSION['username'], "[AMBU] MàJ item \"$nom\" (ID: $id, Actif: $is_active)."); $feedback = ['type' => 'success', 'message' => 'Item Ambu mis à jour.']; } catch (PDOException $e) { $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'Données invalides pour la modification d\'item.']; } }
    if (isset($_POST['delete_ambu_item'])) { $id = (int)$_POST['id']; if ($id > 0) { try { $stmt = $pdo->prepare("SELECT nom, thumbnail, high_res FROM ambu_items WHERE id = ?"); $stmt->execute([$id]); $item = $stmt->fetch(); if ($item) { $pdo->beginTransaction(); $stmt_delete = $pdo->prepare("DELETE FROM ambu_items WHERE id = ?"); $stmt_delete->execute([$id]); if ($item['thumbnail'] && $item['thumbnail'] !== 'noimage.jpg' && file_exists(UPLOAD_PATH . '/' . $item['thumbnail'])) { @unlink(UPLOAD_PATH . '/' . $item['thumbnail']); } if ($item['high_res'] && $item['high_res'] !== 'noimage_high.jpg' && file_exists(UPLOAD_PATH . '/' . $item['high_res'])) { @unlink(UPLOAD_PATH . '/' . $item['high_res']); } $pdo->commit(); log_event($pdo, $_SESSION['username'], "[AMBU] Suppression (définitive) item \"".($item['nom'] ?? 'ID '.$id)."\" (ID: $id)."); $feedback = ['type' => 'success', 'message' => 'Item Ambu supprimé définitivement.']; } } catch (PDOException $e) { $pdo->rollBack(); $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()]; } } else { $feedback = ['type' => 'error', 'message' => 'ID Item invalide.']; } }

    // --- Redirection pour éviter re-soumission ---
    header("Location: admin_checklist.php?tab=" . $active_tab . "&ts=" . time() . "&feedback_type=" . urlencode($feedback['type']) . "&feedback_msg=" . urlencode($feedback['message']) . "#" . $active_tab);
    exit;

} // Fin du grand IF POST


// Récupérer le feedback et l'onglet actif depuis l'URL si redirection
if (isset($_GET['feedback_msg'])) { $feedback['type'] = $_GET['feedback_type'] ?? 'info'; $feedback['message'] = urldecode($_GET['feedback_msg']); }
if (isset($_GET['tab'])) { $active_tab = $_GET['tab'] === 'ambu' ? 'ambu' : 'dps'; }

// =====================================================
// Récupérer les données pour l'affichage (inchangé)
// =====================================================
try {
    $produits = $pdo->query("SELECT p.*, po.nom as pochette_nom FROM produits p LEFT JOIN pochettes po ON p.pochette_id = po.id WHERE p.deleted_at IS NULL ORDER BY po.ordre, p.nom")->fetchAll(PDO::FETCH_ASSOC);
    $pochettes = $pdo->query("SELECT * FROM pochettes ORDER BY ordre, nom")->fetchAll(PDO::FETCH_ASSOC);
    $ambu_items = $pdo->query("SELECT i.*, z.nom as zone_nom FROM ambu_items i LEFT JOIN ambu_zones z ON i.zone_id = z.id ORDER BY z.ordre, i.ordre, i.nom")->fetchAll(PDO::FETCH_ASSOC);
    $ambu_zones = $pdo->query("SELECT * FROM ambu_zones ORDER BY ordre, nom")->fetchAll(PDO::FETCH_ASSOC);
    $ambu_sous_zones_all = $pdo->query("SELECT sz.*, z.nom as zone_nom FROM ambu_sous_zones sz JOIN ambu_zones z ON sz.zone_id = z.id ORDER BY z.ordre, sz.ordre, sz.nom")->fetchAll(PDO::FETCH_ASSOC);
    $ambu_sous_zones_grouped = []; foreach($ambu_sous_zones_all as $sz) { $ambu_sous_zones_grouped[$sz['zone_id']][] = $sz; }
} catch (PDOException $e) {
    $produits = []; $pochettes = []; $ambu_items = []; $ambu_zones = []; $ambu_sous_zones_all = []; $ambu_sous_zones_grouped = [];
    $feedback = ['type' => 'error', 'message' => 'Impossible de charger les données: ' . $e->getMessage()];
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion Items & Structures - Admin</title>
    <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
    <style>
        /* Styles spécifiques à cette page */
        .admin-section { background: var(--bg-pochette); padding: var(--spacing-lg); border-radius: var(--radius-lg); margin-bottom: var(--spacing-xl); }
        .admin-section h2, .admin-section h3, .admin-section h4 { margin-top: 0; margin-bottom: var(--spacing-lg); border-bottom: 2px solid var(--border-color); padding-bottom: var(--spacing-sm); }
        .admin-section h4 { border-bottom-style: dashed; margin-left: var(--spacing-md); } /* Pour sous-zones */

        /* Grille Formulaire */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--spacing-md); align-items: flex-end; margin-bottom: var(--spacing-lg); }
        .form-group { display: flex; flex-direction: column; gap: var(--spacing-xs); }
        label { font-weight: 600; color: var(--text-secondary); font-size: 0.875rem; }
        input[type="text"], input[type="number"], input[type="color"], select { padding: var(--spacing-sm); border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-container); color: var(--text-primary); font-size: 0.9rem; width: 100%; box-sizing: border-box; }
        input[type="color"] { padding: var(--spacing-xs); height: 38px; min-width: 45px; } /* Taille cohérente */
        button { padding: var(--spacing-sm) var(--spacing-md); border: none; border-radius: var(--radius-md); background: var(--color-primary); color: white; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: background-color var(--transition-fast); height: 38px; } /* Taille cohérente */
        button:hover:not(:disabled) { background: var(--color-primary-hover); }
        .btn-danger { background: var(--color-danger); }
        .btn-danger:hover:not(:disabled) { background: #b91c1c; }
        .btn-secondary { background: var(--text-secondary); }
        .btn-secondary:hover:not(:disabled) { background: var(--text-primary); }

         /* Styles Tableau Desktop (adapté de admin_log) */
        .responsive-table-container { width: 100%; overflow-x: auto; margin-bottom: var(--spacing-lg);}
        table { width: 100%; border-collapse: collapse; margin-top: var(--spacing-sm); font-size: 0.9rem; }
        th, td { padding: var(--spacing-sm); border-bottom: 1px solid var(--border-color); text-align: left; vertical-align: middle; white-space: nowrap; }
        th { background: var(--bg-container); font-weight: 600; white-space: normal; }
        tr:hover { background: var(--bg-container); }
        td form { display: contents; } /* Pour que les boutons soient alignés */
        td input, td select { padding: var(--spacing-xs); font-size: 0.85rem; width: 100%; box-sizing: border-box; height: 30px; }
        td input[type="number"] { width: 70px; }
        td input[type="color"] { padding: 2px; height: 30px; min-width: 40px; width: 40px; vertical-align: middle;}
        td input[type="checkbox"] { width: auto; height: auto; vertical-align: middle; margin: 0 auto; display: block;}
        td button { padding: var(--spacing-xs) var(--spacing-sm); font-size: 0.85rem; height: 30px; margin-top: 2px; margin-bottom: 2px; }
        .table-actions { display: flex; gap: var(--spacing-xs); }

        /* Styles Drag Handle */
        .drag-handle { cursor: move; text-align: center; font-size: 1.2rem; color: var(--text-secondary); width: 40px; padding: var(--spacing-sm) 5px; }
        .drag-handle:hover { color: var(--text-primary); }
        .sortable-ghost { opacity: 0.4; background: #cce5ff; } /* Style de l'élément fantôme */

        /* Styles Checkbox 'Actif' */
        .form-group-checkbox { justify-content: center; align-items: center; text-align: center; }
        .form-group-checkbox label { display: flex; align-items: center; gap: var(--spacing-sm); cursor: pointer; justify-content: center;}
        input[type="checkbox"].inline-checkbox { width: 16px; height: 16px; margin:0; vertical-align: middle;} /* Pour tableau */
        td[data-label="Actif"] { text-align: center; }

        /* Styles Sous-Zones */
        .sous-zones-section { margin-top: var(--spacing-xl); padding-top: var(--spacing-lg); border-top: 1px dashed var(--border-color); }
        .sous-zones-list { margin-left: var(--spacing-md); } /* Indentation */

        /* Styles pour le select dynamique de sous-zone */
        .sous-zone-select-wrapper { display: flex; align-items: center; gap: var(--spacing-xs); }
        .sous-zone-color-preview { width: 20px; height: 20px; border-radius: 4px; border: 1px solid var(--border-color); flex-shrink: 0; display: inline-block; vertical-align: middle; margin-right: 5px; background-color: transparent; /* Défaut */ }
        .sous-zone-manual-input { display: none; margin-top: var(--spacing-xs); } /* Caché par défaut */

        /* CSS Responsive (Adapté de admin_log) */
        @media (max-width: 900px) {
            .responsive-table-container { overflow-x: hidden; }
            table thead { display: none; }
            table, table tbody, table tr, table td { display: block; width: 100% !important; }
            table tr { margin-bottom: var(--spacing-lg); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; background: var(--bg-container); }
            table tr form { display: contents; }
            table td { display: flex; justify-content: space-between; align-items: center; padding: var(--spacing-sm) var(--spacing-md); text-align: right; border-bottom: 1px solid var(--border-color); min-height: 40px; white-space: normal; }
            table tr:last-child { margin-bottom: 0; }
            table td:last-child { border-bottom: none; }
            table td::before { content: attr(data-label); font-weight: 600; color: var(--text-secondary); text-align: left; padding-right: var(--spacing-md); flex-shrink: 0; min-width: 90px; }
            table td input[type="text"], table td input[type="number"], table td select { width: auto; max-width: 60%; min-width: 100px; font-size: 0.9rem; padding: var(--spacing-sm); height: 36px;}
            table td input[type="color"] { max-width: 40px; height: 36px; padding: 2px; }
            table td input[type="number"] { width: 70px; }
            table td[data-label="Actif"] { justify-content: flex-end; }
            table td[data-label="Actif"] input[type="checkbox"] { margin: 0; transform: scale(1.2); }
            table td[data-label="Action"] { justify-content: center; padding: var(--spacing-md); background: var(--bg-pochette); }
            table td[data-label="Action"]::before { display: none; }
            table td[data-label="Action"] .table-actions { max-width: 100%; justify-content: center; width: 100%; }
            table td[data-label="Action"] button { font-size: 0.9rem; padding: var(--spacing-sm) var(--spacing-md); height: 40px; }
            td.drag-handle { display: flex; justify-content: center; padding: var(--spacing-sm); min-height: auto; border-bottom: none; background: var(--bg-pochette); }
            td.drag-handle::before { display: none; }
            td.drag-handle span { display: inline-block; padding: 5px; }
            .sous-zones-list { margin-left: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="text-align: center; margin-bottom: 2rem;">📋 Gestion des Items & Structures</h1>

        <?php if (!empty($feedback['message'])): ?>
            <div class="alert alert-<?= $feedback['type'] === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($feedback['message']) ?>
            </div>
        <?php endif; ?>

        <div class="tabs-container">
            <a href="#dps" class="tab-link <?= $active_tab === 'dps' ? 'active' : '' ?>" data-tab="dps">🎒 Sacs DPS</a>
            <a href="#ambu" class="tab-link <?= $active_tab === 'ambu' ? 'active' : '' ?>" data-tab="ambu">🚑 Ambulance</a>
        </div>

        <div id="dps" class="tab-content <?= $active_tab === 'dps' ? 'active' : '' ?>">
            <div class="admin-section">
                 <h2>Gérer les Pochettes (DPS)</h2>
                <div class="responsive-table-container">
                    <table>
                        <thead><tr><th style="width: 40px;" title="Glisser-déposer">☰</th><th>Nom</th><th>Couleur</th><th style="width: 80px;">Ordre</th><th style="width: 200px;">Action</th></tr></thead>
                        <tbody id="pochettes-tbody">
                            <?php foreach ($pochettes as $pochette): ?>
                            <tr data-id="<?= $pochette['id'] ?>">
                                <td class="drag-handle" data-label="Réorg." title="Glisser-déposer"><span>☰</span></td>
                                <form action="admin_checklist.php" method="POST">
                                    <input type="hidden" name="active_tab" value="dps"> <input type="hidden" name="pochette_id" value="<?= $pochette['id'] ?>">
                                    <td data-label="Nom"><input type="text" name="nom" value="<?= htmlspecialchars($pochette['nom']) ?>" required></td>
                                    <td data-label="Couleur"><input type="color" name="color" value="<?= htmlspecialchars($pochette['color']) ?>" required></td>
                                    <td data-label="Ordre"><input type="number" name="ordre" value="<?= $pochette['ordre'] ?>" required></td>
                                    <td data-label="Action"><div class="table-actions"><button type="submit" name="update_pochette">Modif.</button><button type="submit" name="delete_pochette" class="btn-danger" onclick="return confirm('Sûr ? Uniquement si aucun item actif lié.');">Suppr.</button></div></td>
                                </form>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <h3 style="margin-top: 2rem;">Ajouter une pochette (DPS)</h3>
                <form action="admin_checklist.php" method="POST" class="form-grid">
                     <input type="hidden" name="active_tab" value="dps">
                    <div class="form-group"><label for="p_nom">Nom *</label><input type="text" id="p_nom" name="nom" required></div>
                    <div class="form-group"><label for="p_color">Couleur</label><input type="color" id="p_color" name="color" value="#D3D3D3" required></div>
                    <div class="form-group"><label for="p_ordre">Ordre</label><input type="number" id="p_ordre" name="ordre" value="<?= count($pochettes)*10 + 10 ?>" required></div>
                    <button type="submit" name="add_pochette">Ajouter Pochette</button>
                </form>
            </div>

            <div class="admin-section">
                <h2>Gérer les Produits (DPS)</h2>
                <div class="responsive-table-container">
                    <table>
                        <thead><tr><th>Nom</th><th>Quantité</th><th>Pochette</th><th>Actif</th><th style="width: 200px;">Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($produits as $produit): ?>
                            <tr>
                                <form action="admin_checklist.php" method="POST">
                                    <input type="hidden" name="active_tab" value="dps"> <input type="hidden" name="id" value="<?= $produit['id'] ?>">
                                    <td data-label="Nom"><input type="text" name="nom" value="<?= htmlspecialchars($produit['nom']) ?>" required></td>
                                    <td data-label="Quantité"><input type="text" name="quantite" value="<?= htmlspecialchars($produit['quantite']) ?>"></td>
                                    <td data-label="Pochette">
                                        <select name="pochette_id" required>
                                            <?php foreach ($pochettes as $pochette): ?><option value="<?= $pochette['id'] ?>" <?= $produit['pochette_id'] == $pochette['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pochette['nom']) ?></option><?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="Actif"><input type="checkbox" class="inline-checkbox" name="is_active" value="1" <?= $produit['is_active'] ? 'checked' : '' ?>></td>
                                    <td data-label="Action"><div class="table-actions"><button type="submit" name="update_product">Modif.</button><button type="submit" name="delete_product" class="btn-danger" onclick="return confirm('Archiver ce produit ?');">Archiver</button></div></td>
                                </form>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <h3 style="margin-top: 2rem;">Ajouter un produit (DPS)</h3>
                <form action="admin_checklist.php" method="POST" class="form-grid">
                     <input type="hidden" name="active_tab" value="dps">
                    <div class="form-group"><label for="add_nom_dps">Nom produit *</label><input type="text" id="add_nom_dps" name="nom" required></div>
                    <div class="form-group"><label for="add_quantite_dps">Quantité</label><input type="text" id="add_quantite_dps" name="quantite"></div>
                    <div class="form-group">
                        <label for="add_pochette_id">Pochette *</label>
                        <select id="add_pochette_id" name="pochette_id" required><option value="">-- Choisir --</option><?php foreach ($pochettes as $pochette): ?><option value="<?= $pochette['id'] ?>"><?= htmlspecialchars($pochette['nom']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="form-group form-group-checkbox"><label for="add_prod_is_active"><input type="checkbox" id="add_prod_is_active" name="is_active" value="1" checked> Actif</label></div>
                    <button type="submit" name="add_product">Ajouter Produit</button>
                </form>
            </div>
        </div>

        <div id="ambu" class="tab-content <?= $active_tab === 'ambu' ? 'active' : '' ?>">
             <input type="hidden" name="active_tab" value="ambu">

            <div class="admin-section">
                <h2>Gérer les Zones (Ambulance)</h2>
                <div class="responsive-table-container">
                     <table>
                        <thead><tr><th style="width: 40px;" title="Glisser-déposer">☰</th><th>Nom</th><th>Couleur</th><th style="width: 80px;">Ordre</th><th style="width: 200px;">Action</th></tr></thead>
                        <tbody id="ambu-zones-tbody">
                            <?php foreach ($ambu_zones as $zone): ?>
                            <tr data-id="<?= $zone['id'] ?>">
                                <td class="drag-handle" data-label="Réorg." title="Glisser-déposer"><span>☰</span></td>
                                <form action="admin_checklist.php" method="POST">
                                    <input type="hidden" name="active_tab" value="ambu"> <input type="hidden" name="ambu_zone_id" value="<?= $zone['id'] ?>">
                                    <td data-label="Nom"><input type="text" name="nom" value="<?= htmlspecialchars($zone['nom']) ?>" required></td>
                                    <td data-label="Couleur"><input type="color" name="color" value="<?= htmlspecialchars($zone['color']) ?>" required></td>
                                    <td data-label="Ordre"><input type="number" name="ordre" value="<?= $zone['ordre'] ?>" required></td>
                                    <td data-label="Action"><div class="table-actions"><button type="submit" name="update_ambu_zone">Modif.</button><button type="submit" name="delete_ambu_zone" class="btn-danger" onclick="return confirm('Sûr ? Supprime aussi sous-zones. Uniquement si aucun item ACTIF lié.');">Suppr.</button></div></td>
                                </form>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                 <h3 style="margin-top: 2rem;">Ajouter une zone (Ambulance)</h3>
                <form action="admin_checklist.php" method="POST" class="form-grid">
                    <input type="hidden" name="active_tab" value="ambu">
                    <div class="form-group"><label for="z_nom">Nom *</label><input type="text" id="z_nom" name="nom" required></div>
                    <div class="form-group"><label for="z_color">Couleur</label><input type="color" id="z_color" name="color" value="#EEEEEE" required></div>
                    <div class="form-group"><label for="z_ordre">Ordre</label><input type="number" id="z_ordre" name="ordre" value="<?= count($ambu_zones)*10 + 10 ?>" required></div>
                    <button type="submit" name="add_ambu_zone">Ajouter Zone</button>
                </form>

                 <div class="sous-zones-section">
                    <h3>Gérer les Sous-Zones (par Zone)</h3>
                    <?php if (empty($ambu_zones)): ?><p>Créez d'abord des Zones.</p><?php else: ?>
                        <?php foreach ($ambu_zones as $zone): ?>
                            <h4><?= htmlspecialchars($zone['nom']) ?></h4>
                            <div class="responsive-table-container sous-zones-list">
                                <table>
                                    <thead><tr><th style="width: 40px;" title="Glisser-déposer">☰</th><th>Nom Sous-Zone</th><th>Couleur</th><th style="width: 80px;">Ordre</th><th style="width: 200px;">Action</th></tr></thead>
                                    <tbody class="ambu-sous-zones-tbody" data-zone-id="<?= $zone['id'] ?>">
                                        <?php if (isset($ambu_sous_zones_grouped[$zone['id']])): ?>
                                            <?php foreach ($ambu_sous_zones_grouped[$zone['id']] as $sz): ?>
                                            <tr data-id="<?= $sz['id'] ?>">
                                                <td class="drag-handle" data-label="Réorg." title="Glisser-déposer"><span>☰</span></td>
                                                <form action="admin_checklist.php" method="POST">
                                                    <input type="hidden" name="active_tab" value="ambu"> <input type="hidden" name="ambu_sous_zone_id" value="<?= $sz['id'] ?>"> <input type="hidden" name="zone_id" value="<?= $zone['id'] ?>">
                                                    <td data-label="Nom"><input type="text" name="nom" value="<?= htmlspecialchars($sz['nom']) ?>" required></td>
                                                    <td data-label="Couleur"><input type="color" name="color" value="<?= htmlspecialchars($sz['color']) ?>" required></td>
                                                    <td data-label="Ordre"><input type="number" name="ordre" value="<?= $sz['ordre'] ?>" required></td>
                                                    <td data-label="Action"><div class="table-actions"><button type="submit" name="update_ambu_sous_zone">Modif.</button><button type="submit" name="delete_ambu_sous_zone" class="btn-danger" onclick="return confirm('Sûr ? Les items liés seront déliés.');">Suppr.</button></div></td>
                                                </form>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" style="text-align: center; font-style: italic; padding: var(--spacing-md);">Aucune sous-zone définie pour cette zone.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <h5 style="margin-top: 1rem; border: none; font-size: 0.9rem;">Ajouter sous-zone à "<?= htmlspecialchars($zone['nom']) ?>"</h5>
                                <form action="admin_checklist.php" method="POST" class="form-grid">
                                    <input type="hidden" name="active_tab" value="ambu"> <input type="hidden" name="zone_id" value="<?= $zone['id'] ?>">
                                    <div class="form-group"><label for="sz_nom_<?= $zone['id'] ?>">Nom *</label><input type="text" id="sz_nom_<?= $zone['id'] ?>" name="nom" required></div>
                                    <div class="form-group"><label for="sz_color_<?= $zone['id'] ?>">Couleur</label><input type="color" id="sz_color_<?= $zone['id'] ?>" name="color" value="#FFFFFF" required></div>
                                    <div class="form-group"><label for="sz_ordre_<?= $zone['id'] ?>">Ordre</label><input type="number" id="sz_ordre_<?= $zone['id'] ?>" name="ordre" value="<?= isset($ambu_sous_zones_grouped[$zone['id']]) ? count($ambu_sous_zones_grouped[$zone['id']])*10 + 10 : 10 ?>" required></div>
                                    <button type="submit" name="add_ambu_sous_zone">Ajouter Sous-Zone</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                 </div>
            </div>

            <div class="admin-section">
                <h2>Gérer les Items (Ambulance)</h2>
                 <div class="responsive-table-container">
                    <table>
                        <thead><tr><th>Nom</th><th>Quantité</th><th>Zone</th><th>Sous-Zone</th><th>Ordre</th><th>Actif</th><th style="width: 200px;">Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($ambu_items as $item): ?>
                            <tr>
                                <form action="admin_checklist.php" method="POST">
                                    <input type="hidden" name="active_tab" value="ambu"> <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <td data-label="Nom"><input type="text" name="nom" value="<?= htmlspecialchars($item['nom']) ?>" required></td>
                                    <td data-label="Quantité"><input type="text" name="quantite" value="<?= htmlspecialchars($item['quantite']) ?>"></td>
                                    <td data-label="Zone">
                                        <select class="zone-select" name="zone_id" required>
                                            <?php foreach ($ambu_zones as $zone): ?>
                                                <option value="<?= $zone['id'] ?>" <?= $item['zone_id'] == $zone['id'] ? 'selected' : '' ?>><?= htmlspecialchars($zone['nom']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="Sous-Zone">
                                        <div class="sous-zone-wrapper">
                                            <div class="sous-zone-select-wrapper">
                                                <span class="sous-zone-color-preview"></span>
                                                <select name="sous_zone_nom_select" class="sous-zone-select" style="flex-grow: 1;"></select>
                                            </div>
                                            <input type="text" name="sous_zone_nom_manual" class="sous-zone-manual-input" placeholder="Nom manuel">
                                            <input type="hidden" name="sous_zone_nom" class="final-sous-zone-nom" value="<?= htmlspecialchars($item['sous_zone_nom'] ?? '') ?>">
                                        </div>
                                    </td>
                                    <td data-label="Ordre"><input type="number" name="ordre" value="<?= $item['ordre'] ?>" required></td>
                                    <td data-label="Actif"><input type="checkbox" class="inline-checkbox" name="is_active" value="1" <?= $item['is_active'] ? 'checked' : '' ?>></td>
                                    <td data-label="Action"><div class="table-actions"><button type="submit" name="update_ambu_item">Modif.</button><button type="submit" name="delete_ambu_item" class="btn-danger" onclick="return confirm('Sûr (suppression DÉFINITIVE) ?');">Suppr.</button></div></td>
                                </form>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <h3 style="margin-top: 2rem;">Ajouter un item (Ambulance)</h3>
                 <form action="admin_checklist.php" method="POST" class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));" id="add-ambu-item-form">
                    <input type="hidden" name="active_tab" value="ambu">
                    <div class="form-group"><label for="i_nom">Nom item *</label><input type="text" id="i_nom" name="nom" required></div>
                    <div class="form-group"><label for="i_quantite">Quantité</label><input type="text" id="i_quantite" name="quantite"></div>
                    <div class="form-group">
                        <label for="i_zone_id">Zone *</label>
                        <select id="i_zone_id" class="zone-select" name="zone_id" required><option value="">-- Choisir --</option><?php foreach ($ambu_zones as $zone): ?><option value="<?= $zone['id'] ?>"><?= htmlspecialchars($zone['nom']) ?></option><?php endforeach; ?></select>
                    </div>
                     <div class="form-group">
                        <label for="i_sous_zone_select">Sous-Zone (Optionnel)</label>
                         <div class="sous-zone-wrapper">
                             <div class="sous-zone-select-wrapper">
                                 <span class="sous-zone-color-preview"></span>
                                 <select id="i_sous_zone_select" name="sous_zone_nom_select" class="sous-zone-select" style="flex-grow: 1;"><option value="">-- Choisir Zone d'abord --</option></select>
                             </div>
                             <input type="text" id="i_sous_zone_manual" name="sous_zone_nom_manual" class="sous-zone-manual-input" placeholder="Nom manuel">
                             <input type="hidden" name="sous_zone_nom" class="final-sous-zone-nom" value="">
                         </div>
                    </div>
                    <div class="form-group"><label for="i_ordre">Ordre</label><input type="number" id="i_ordre" name="ordre" value="10" required></div>
                    <div class="form-group form-group-checkbox"><label for="add_item_is_active"><input type="checkbox" id="add_item_is_active" name="is_active" value="1" checked> Actif</label></div>
                    <button type="submit" name="add_ambu_item">Ajouter Item</button>
                </form>
            </div>

        </div> <div class="actions" style="margin-top: var(--spacing-xl);">
            <button onclick="window.location.href='admin.php';" class="btn-secondary">← Retour Admin</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // --- GESTION DES ONGLETS ---
            const tabLinks = document.querySelectorAll('.tab-link');
            const tabContents = document.querySelectorAll('.tab-content');

            function setActiveTab(tabId) {
                if (!tabId || !document.getElementById(tabId)) {
                   tabId = 'dps'; // Fallback si tab invalide
                   console.warn("Onglet invalide ou non trouvé, retour à 'dps'");
                }
                
                tabLinks.forEach(link => {
                    link.classList.toggle('active', link.getAttribute('data-tab') === tabId);
                });
                tabContents.forEach(content => {
                    content.classList.toggle('active', content.id === tabId);
                });
                // Met à jour les champs cachés 'active_tab'
                document.querySelectorAll('form input[name="active_tab"]').forEach(input => { input.value = tabId; });
                // Mémorise dans l'URL hash (sans recharger)
                try {
                    if (history.pushState) {
                         // Vérifie que l'URL actuelle n'a pas déjà ce hash pour éviter entrées multiples identiques
                        if(window.location.hash !== '#' + tabId) {
                            history.pushState(null, null, '#' + tabId);
                        }
                    } else {
                        window.location.hash = tabId;
                    }
                } catch(e) { console.warn("Cannot update hash:", e); }
            }

            tabLinks.forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault(); // Important car on utilise des liens <a>
                    const tabId = link.getAttribute('data-tab');
                    setActiveTab(tabId);
                });
            });

            // Lire l'onglet depuis l'URL hash au chargement OU utiliser celui de PHP
            let currentTab = '<?= $active_tab ?>'; // Priorité à PHP après POST/Redirect
            const hash = window.location.hash.substring(1);
            if (hash && document.getElementById(hash)) {
                 // Si on vient d'une redirection POST (avec feedback), PHP a priorité, sinon on prend le hash
                 if (!<?= json_encode(!empty($feedback['message'])) ?>) {
                     currentTab = hash;
                 }
            }
             // S'assurer que currentTab est valide avant de l'activer
            if (!document.getElementById(currentTab)) {
                currentTab = 'dps'; // Fallback final
            }
            setActiveTab(currentTab); // Active l'onglet initial


            // --- DRAG-DROP POCHETTES (DPS) ---
            const tbodyPochettes = document.getElementById('pochettes-tbody');
            if (tbodyPochettes) {
                 new Sortable(tbodyPochettes, { animation: 150, handle: '.drag-handle', ghostClass: 'sortable-ghost',
                    onEnd: function (evt) {
                        const items = tbodyPochettes.querySelectorAll('tr'); const order = Array.from(items).map(tr => tr.dataset.id);
                        const formData = new FormData(); formData.append('update_pochette_order', '1'); formData.append('order', JSON.stringify(order));
                        fetch('admin_checklist.php', { method: 'POST', body: formData }).then(r => r.json())
                          .then(d => { if (!d.success) alert('Erreur D&D DPS Pochettes: ' + d.message); else console.log("Ordre pochettes sauvegardé."); })
                          .catch(e => { console.error(e); alert('Erreur connexion D&D DPS Pochettes.'); });
                    }
                });
            }

            // --- DRAG-DROP ZONES (AMBU) ---
            const tbodyAmbuZones = document.getElementById('ambu-zones-tbody');
            if (tbodyAmbuZones) {
                 new Sortable(tbodyAmbuZones, { animation: 150, handle: '.drag-handle', ghostClass: 'sortable-ghost',
                    onEnd: function (evt) {
                        const items = tbodyAmbuZones.querySelectorAll('tr'); const order = Array.from(items).map(tr => tr.dataset.id);
                        const formData = new FormData(); formData.append('update_ambu_zone_order', '1'); formData.append('order', JSON.stringify(order));
                        fetch('admin_checklist.php', { method: 'POST', body: formData }).then(r => r.json())
                          .then(d => { if (!d.success) alert('Erreur D&D Ambu Zones: ' + d.message); else console.log("Ordre zones sauvegardé."); })
                          .catch(e => { console.error(e); alert('Erreur connexion D&D Ambu Zones.'); });
                    }
                });
            }

            // --- DRAG-DROP SOUS-ZONES (AMBU) ---
            const tbodiesAmbuSousZones = document.querySelectorAll('.ambu-sous-zones-tbody');
            tbodiesAmbuSousZones.forEach(tbodySZ => {
                new Sortable(tbodySZ, { animation: 150, handle: '.drag-handle', ghostClass: 'sortable-ghost',
                    onEnd: function (evt) {
                        const items = tbodySZ.querySelectorAll('tr'); const order = Array.from(items).map(tr => tr.dataset.id); const zoneId = tbodySZ.getAttribute('data-zone-id');
                        const formData = new FormData(); formData.append('update_ambu_sous_zone_order', '1'); formData.append('order', JSON.stringify(order)); formData.append('zone_id', zoneId);
                        fetch('admin_checklist.php', { method: 'POST', body: formData }).then(r => r.json())
                          .then(d => { if (!d.success) alert('Erreur D&D Ambu Sous-Zones: ' + d.message); else console.log("Ordre sous-zones sauvegardé pour zone "+zoneId); })
                          .catch(e => { console.error(e); alert('Erreur connexion D&D Ambu Sous-Zones.'); });
                    }
                });
            });

            // --- GESTION DYNAMIQUE SOUS-ZONES (AMBU ITEMS) ---
            const populateSousZones = async (selectZoneEl, wrapperEl, currentSousZoneName) => {
                // Vérifier si les éléments existent avant de continuer
                const selectSousZoneEl = wrapperEl.querySelector('.sous-zone-select');
                const inputManualEl = wrapperEl.querySelector('.sous-zone-manual-input');
                const hiddenFinalInputEl = wrapperEl.querySelector('.final-sous-zone-nom');
                const colorPreviewEl = wrapperEl.querySelector('.sous-zone-color-preview');
                
                if (!selectZoneEl || !selectSousZoneEl || !inputManualEl || !hiddenFinalInputEl || !colorPreviewEl) {
                    console.error("Éléments manquants pour la gestion des sous-zones dans:", wrapperEl);
                    if(selectSousZoneEl) selectSousZoneEl.innerHTML = '<option value="">Erreur init.</option>';
                    return; // Ne pas continuer si un élément manque
                }

                const zoneId = selectZoneEl.value;
                selectSousZoneEl.innerHTML = '<option value="">Chargement...</option>';
                inputManualEl.style.display = 'none';
                inputManualEl.value = '';
                hiddenFinalInputEl.value = '';
                colorPreviewEl.style.backgroundColor = 'transparent';

                if (!zoneId) {
                    selectSousZoneEl.innerHTML = '<option value="">-- Choisir Zone d\'abord --</option>';
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('action', 'list_sous_zones'); // **ACTION AJOUTÉE**
                    formData.append('zone_id', zoneId);

                    // **URL CORRIGÉE**
                    const response = await fetch('ajax_ambu.php', { method: 'POST', body: formData });
                    if (!response.ok) throw new Error(`Erreur HTTP ${response.status}`);
                    const data = await response.json();

                    if (!data.success || !Array.isArray(data.sous_zones)) {
                         throw new Error(data.message || 'Réponse invalide du serveur.');
                    }

                    selectSousZoneEl.innerHTML = '<option value="">-- Aucune (item direct) --</option>';
                    selectSousZoneEl.innerHTML += '<option value="_manual_">-- Saisie manuelle --</option>';

                    let foundCurrent = false;
                    const currentTrimmed = (currentSousZoneName || '').trim();

                    data.sous_zones.forEach(sz => {
                        const option = document.createElement('option');
                        const szNomTrimmed = (sz.nom || '').trim();
                        option.value = szNomTrimmed;
                        option.textContent = sz.nom;
                        option.dataset.color = sz.color || '#FFFFFF';
                        selectSousZoneEl.appendChild(option);

                        if (currentTrimmed && szNomTrimmed === currentTrimmed) {
                            option.selected = true;
                            foundCurrent = true;
                            colorPreviewEl.style.backgroundColor = option.dataset.color;
                            hiddenFinalInputEl.value = szNomTrimmed;
                        }
                    });

                    if (currentSousZoneName && !foundCurrent) {
                        selectSousZoneEl.value = '_manual_';
                        inputManualEl.value = currentSousZoneName;
                        inputManualEl.style.display = 'block';
                        hiddenFinalInputEl.value = currentTrimmed;
                        colorPreviewEl.style.backgroundColor = 'transparent';
                    } else if (!currentSousZoneName) {
                         selectSousZoneEl.value = '';
                         hiddenFinalInputEl.value = '';
                         colorPreviewEl.style.backgroundColor = 'transparent';
                    }

                } catch (error) {
                    console.error("Erreur chargement sous-zones pour zone "+zoneId+":", error);
                    selectSousZoneEl.innerHTML = '<option value="">Erreur chargement</option>';
                     colorPreviewEl.style.backgroundColor = 'transparent';
                    if(currentSousZoneName){ // Fallback en mode manuel si erreur
                        selectSousZoneEl.value = '_manual_';
                        inputManualEl.value = currentSousZoneName;
                        inputManualEl.style.display = 'block';
                        hiddenFinalInputEl.value = (currentSousZoneName || '').trim();
                    }
                }
            };

            // Fonction pour mettre à jour la valeur finale cachée et la couleur
            const updateFinalSousZone = (wrapperEl) => {
                const selectSousZoneEl = wrapperEl.querySelector('.sous-zone-select');
                const inputManualEl = wrapperEl.querySelector('.sous-zone-manual-input');
                const hiddenFinalInputEl = wrapperEl.querySelector('.final-sous-zone-nom');
                const colorPreviewEl = wrapperEl.querySelector('.sous-zone-color-preview');

                 if (!selectSousZoneEl || !inputManualEl || !hiddenFinalInputEl || !colorPreviewEl) return; // Sécurité

                 const selectedValue = selectSousZoneEl.value;
                 const selectedOption = selectSousZoneEl.options[selectSousZoneEl.selectedIndex];

                 if (selectedValue === '_manual_') {
                     inputManualEl.style.display = 'block';
                     hiddenFinalInputEl.value = inputManualEl.value.trim();
                     colorPreviewEl.style.backgroundColor = 'transparent';
                 } else {
                     inputManualEl.style.display = 'none';
                     hiddenFinalInputEl.value = selectedValue; // select.value est déjà trimé
                     colorPreviewEl.style.backgroundColor = selectedOption ? (selectedOption.dataset.color || 'transparent') : 'transparent';
                 }
            };

            // Initialiser et attacher les listeners pour chaque item
            document.querySelectorAll('#ambu tbody tr, #add-ambu-item-form').forEach(container => {
                const selectZone = container.querySelector('.zone-select');
                const wrapper = container.querySelector('.sous-zone-wrapper'); // Le div parent

                if (selectZone && wrapper) {
                    const selectSousZone = wrapper.querySelector('.sous-zone-select');
                    const inputManual = wrapper.querySelector('.sous-zone-manual-input');
                    const hiddenFinal = wrapper.querySelector('.final-sous-zone-nom');
                    const colorPreview = wrapper.querySelector('.sous-zone-color-preview');
                    // Lire la valeur initiale depuis le champ caché
                    const currentSousZoneName = hiddenFinal ? hiddenFinal.value : '';

                     if (!selectSousZone || !inputManual || !hiddenFinal || !colorPreview) {
                        console.error("Un élément de sous-zone manque dans:", container);
                        return; // Ne pas continuer si un élément manque
                    }

                    // Listener changement ZONE
                    selectZone.addEventListener('change', () => {
                        populateSousZones(selectZone, wrapper, ''); // Reset sous-zone
                    });

                    // Listener changement SELECT Sous-Zone
                    selectSousZone.addEventListener('change', () => {
                        updateFinalSousZone(wrapper);
                        if (selectSousZone.value === '_manual_') {
                             // Timeout léger pour s'assurer que l'input est visible avant le focus
                            setTimeout(() => inputManual.focus(), 50);
                        }
                    });

                    // Listener INPUT Manuel
                    inputManual.addEventListener('input', () => {
                       if (inputManual.style.display !== 'none') {
                           hiddenFinal.value = inputManual.value.trim();
                       }
                    });

                    // Peuplement initial (si une zone est déjà sélectionnée)
                    if (selectZone.value) {
                       populateSousZones(selectZone, wrapper, currentSousZoneName);
                    } else {
                        // État initial si aucune zone sélectionnée
                        selectSousZone.innerHTML = '<option value="">-- Choisir Zone d\'abord --</option>';
                        inputManual.style.display = 'none';
                        colorPreview.style.backgroundColor = 'transparent';
                    }
                } else {
                     console.warn("Sélecteur de zone ou wrapper de sous-zone manquant dans:", container);
                }
            });

        }); // Fin DOMContentLoaded
    </script>

</body>
</html>
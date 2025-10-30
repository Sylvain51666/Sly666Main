<?php
// =====================================================
// save_checklist_ambu.php - MODIFIÉ V7 (Correction Heure UTC)
// - Gère la sauvegarde de la checklist AMBULANCE
// - CORRIGÉ V4: Recherche NOM dans BDD
// - AJOUT V5: Insère le type 'AMBU' dans checklists_history
// - CORRECTION V6: La requête INSERT avait 6 '?' pour 5 variables.
// - CORRECTION V7: Utilise l'heure UTC générée par PHP au lieu de NOW().
// =====================================================

require_once 'access_control.php';
require_login('user');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

try {
    $user = $_SESSION['username'];
    $items_manquants_list = [];
    $items_defaillants_list = [];
    $products_data = $data['products'] ?? [];

    $stmt_get_name = $pdo->prepare("SELECT nom FROM ambu_items WHERE id = ?");

    foreach ($products_data as $id => $details) {
        $product_name = $details['nom'] ?? null;
        if (empty($product_name) && is_numeric($id) && $id > 0) {
            $stmt_get_name->execute([$id]);
            $product_name_row = $stmt_get_name->fetch(PDO::FETCH_ASSOC);
            if ($product_name_row) {
                $product_name = $product_name_row['nom'];
            } else {
                $product_name = 'Nom inconnu (ID: ' . $id . ')';
            }
        } elseif (empty($product_name)) {
             $product_name = 'Nom inconnu (ID: ' . $id . ')';
        }

        if (($details['etat'] ?? 'vide') === 'manquant') { // Ajouter ?? 'vide' par sécurité
            $items_manquants_list[] = $product_name;
        } elseif (($details['etat'] ?? 'vide') === 'defaillant') {
            $items_defaillants_list[] = $product_name;
        }
    }

    if (empty($items_manquants_list) && empty($items_defaillants_list)) {
        $status = 'complete';
        $status_fr = 'Complète';
    } else {
        $status = 'with_issues';
        $status_fr = 'Avec problèmes';
    }

    $data_json = json_encode($data['products'], JSON_UNESCAPED_UNICODE);
    $commentaire = $data['commentaire'] ?? '';

    // Etape 1: Sauvegarder l'historique - AJOUT de 'type'
    // === CORRECTION V7: Utiliser l'heure UTC générée par PHP au lieu de NOW() ===
    $stmt_history = $pdo->prepare("
        INSERT INTO checklists_history
        (user, type, status, commentaire, data_json, date_validation)
        VALUES (?, 'AMBU', ?, ?, ?, ?) -- Remplacer NOW() par un '?'
    ");

    // Générer l'heure UTC maintenant en PHP
    $utc_now = new DateTime("now", new DateTimeZone("UTC"));
    $utc_now_str = $utc_now->format('Y-m-d H:i:s');

     // Mise à jour de execute() pour inclure les 5 variables (dont l'heure UTC)
    $stmt_history->execute([
        $user,
        // type est 'AMBU'
        $status,
        $commentaire,
        $data_json,
        $utc_now_str // Passer la chaîne UTC générée
    ]);
    $checklist_id = $pdo->lastInsertId();

    // Etape 2: Sauvegarder les items
    $stmt_item = $pdo->prepare("
        INSERT INTO checklist_history_items (history_id, produit_id, status_constate)
        VALUES (?, ?, ?)
    ");
    foreach ($products_data as $produit_id => $details) {
        if (is_numeric($produit_id) && $produit_id > 0) {
            $stmt_item->execute([
                $checklist_id,
                (int)$produit_id,
                $details['etat'] ?? 'vide' // Ajouter ?? 'vide' par sécurité
            ]);
        } else {
            error_log("save_checklist_ambu.php: ID de produit non valide trouvé dans les données JSON: " . print_r($produit_id, true));
        }
    }

    // Etape 3: Logger l'événement
    log_event($pdo, $user, "Validation checklist [AMBU] #$checklist_id");

    // Etape 4: Envoyer l'email (inchangé)
    $emailSent = sendChecklistEmail(
        $pdo,
        $checklist_id,
        $user,
        $status_fr,
        $items_manquants_list,
        $items_defaillants_list,
        $commentaire
    );

    echo json_encode([
        'success' => true,
        'checklist_id' => $checklist_id,
        'message' => 'Checklist (Ambu) enregistrée' . ($emailSent ? ' et notifiée' : ''),
        'status' => $status,
        'email_sent' => $emailSent
    ]);

} catch (PDOException $e) {
    error_log("Erreur save_checklist_ambu: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Erreur: ' . $e->getMessage()
    ]);
}

// La fonction sendChecklistEmail reste inchangée...
if (!function_exists('sendChecklistEmail')) {
     function sendChecklistEmail($pdo, $checklist_id, $user, $status_fr, $items_manquants, $items_defaillants, $commentaire) {
         global $app_settings;
         // ... (code de la fonction inchangé) ...
        try {
            // 1. Récupérer les paramètres email
            $destinataire = $app_settings['email_destination'] ?? 'noreply@exemple.com';
            $condition = $app_settings['email_sendCondition'] ?? 'issues_only';
            $template = $app_settings['email_template_html'] ?? 'Erreur: Template non trouvé.';

            // 2. Vérifier la condition d'envoi
            $has_issues = !empty($items_manquants) || !empty($items_defaillants);
            if ($condition === 'issues_only' && !$has_issues) {
                return true;
            }
             if (empty($destinataire)) {
                 log_event($pdo, 'Système', "Email non envoyé (checklist #$checklist_id): Aucun destinataire configuré.");
                 return false;
            }

            // 3. Préparer les variables
            $now = new DateTime('now', new DateTimeZone('Europe/Paris'));
            $date_fr = format_date_fr($now->format('Y-m-d H:i:s'));

            $manquants_html = empty($items_manquants) ? 'Aucun' : '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $items_manquants)) . '</li></ul>';
            $defaillants_html = empty($items_defaillants) ? 'Aucun' : '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $items_defaillants)) . '</li></ul>';

            $variables = [
                '[USER]'              => htmlspecialchars($user),
                '[DATE_FR]'           => $date_fr,
                '[STATUS]'            => $status_fr,
                '[CHECKLIST_ID]'      => $checklist_id,
                '[COMMENTAIRE]'       => nl2br(htmlspecialchars($commentaire)),
                '[ITEMS_MANQUANTS]'   => $manquants_html,
                '[ITEMS_DEFAILLANTS]' => $defaillants_html,
            ];

            // 4. Remplacer les variables
            $message_html = str_replace(array_keys($variables), array_values($variables), $template);

            // 5. Préparer l'envoi
            $prefix = "🚑 [AMBU]"; // Forcé pour AMBU
            $sujet = "$prefix Checklist #$checklist_id ($status_fr)";
             if ($has_issues) {
                $sujet = "$prefix ⚠️ Checklist #$checklist_id ($status_fr)";
            }
            $expediteur_name = "Checklist Ambulance"; // Nom spécifique

            $expediteur_email = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'votre-domaine.com');

            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: $expediteur_name <$expediteur_email>\r\n";
            $headers .= "Reply-To: $expediteur_email\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            // 6. Envoyer
             mb_internal_encoding("UTF-8");
             $encoded_subject = mb_encode_mimeheader($sujet, "UTF-8", "B", "\r\n");
             $result = mail($destinataire, $encoded_subject, $message_html, $headers);

            if ($result) {
                log_event($pdo, 'Système', "Email de notification envoyé pour checklist #$checklist_id à $destinataire");
                return true;
            } else {
                log_event($pdo, 'Système', "Échec envoi email pour checklist #$checklist_id");
                $error = error_get_last();
                if ($error) {
                     error_log("Erreur mail(): " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
                }
                return false;
            }

        } catch (Exception $e) {
            error_log("Erreur sendChecklistEmail (ambu): " . $e->getMessage());
            log_event($pdo, 'Système', "Erreur critique envoi email: " . $e->getMessage());
            return false;
        }
    }
}
?>
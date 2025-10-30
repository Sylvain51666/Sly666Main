<?php
// export_csv.php - CORRIGÉ V5 (Ajout include access_control.php + Robustesse)
ob_start(); // Start output buffering

// --- Augmenter les limites (si possible) ---
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', 120);

session_start();
require_once 'db_connection.php'; // Inclut functions.php
require_once 'access_control.php'; // *** AJOUTÉ ICI ***

// --- Vérifications de l'existence des fonctions critiques ---
if (!function_exists('is_logged_in')) {
    // Fallback simple si non défini ailleurs
    function is_logged_in() {
        return isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['role']);
    }
    error_log("Warning: is_logged_in() was not defined, using fallback in export_csv.php");
}

// check_permission est maintenant inclus via access_control.php, mais on garde la vérification au cas où
if (!function_exists('check_permission')) {
    ob_end_clean();
    http_response_code(500);
    error_log("FATAL ERROR in export_csv.php: Function check_permission() is not defined! (access_control.php might be missing or corrupted)");
    die('Erreur interne du serveur: Fonction de permission manquante.');
}

if (!function_exists('format_date_fr')) {
    // Fallback pour format_date_fr (Version Plan B - Heure Locale) avec robustesse
    function format_date_fr($date_str_local) {
        if (empty($date_str_local) || $date_str_local === '0000-00-00 00:00:00' || strtotime($date_str_local) === false) {
             return 'N/A';
        }
        try {
            $date_local = new DateTime($date_str_local);
            if ($date_local === false) { throw new Exception("DateTime creation failed for input: [" . $date_str_local . "]"); }

            if (class_exists('IntlDateFormatter')) {
                $locale = 'fr_FR';
                $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, IntlDateFormatter::GREGORIAN, 'EEEE d MMMM yyyy \'à\' HH\'h\'mm');
                 if (!$formatter) { throw new Exception("IntlDateFormatter creation failed. Error code: " . intl_get_error_code() . ", Message: " . intl_get_error_message()); }
                 $formattedDate = $formatter->format($date_local);
                 if ($formattedDate === false) { throw new Exception("IntlDateFormatter format failed. Error code: " . $formatter->getErrorCode() . ", Message: " . $formatter->getErrorMessage()); }
                 return $formattedDate;
            } else {
                return $date_local->format('d/m/Y H:i');
            }
        } catch (Exception $e) {
            error_log("Erreur format_date_fr dans export_csv: " . $e->getMessage() . " pour date string: [" . $date_str_local . "]");
            return $date_str_local . ' (ERR_FORMAT)';
        }
    }
     error_log("Warning: format_date_fr() was not defined, using fallback in export_csv.php");
}
// --- Fin vérifications fonctions ---

// Contrôle d'accès
if (!is_logged_in() || !check_permission('editor')) {
    ob_end_clean();
    http_response_code(403);
    die('Accès refusé. Vous devez être connecté avec un rôle Éditeur ou Administrateur.');
}

// Détection type d'export
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$type = 'checklists';
if (strpos($referer, 'admin_log.php') !== false) {
    $type = 'logs';
} elseif (strpos($referer, 'admin_dashboard.php') !== false) {
    $type = 'checklists';
}

// Filtres optionnels
$filter_user = trim($_GET['filter_user'] ?? '');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');

$output = null;
try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Erreur critique: Connexion à la base de données non établie.");
    }

    // --- Construction Clause WHERE ---
    $where_parts = []; $params = [];
    $user_col_placeholder = '{user_col}'; $date_col_placeholder = '{date_col}';
    if (!empty($filter_user)) { $where_parts[] = "$user_col_placeholder LIKE :user"; $params[':user'] = $filter_user . '%'; }
    if (!empty($start_date)) { $where_parts[] = "$date_col_placeholder >= :start_date"; $params[':start_date'] = $start_date . ' 00:00:00'; }
    if (!empty($end_date)) { $where_parts[] = "$date_col_placeholder <= :end_date"; $params[':end_date'] = $end_date . ' 23:59:59'; }
    $where_base_template = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';
    // --- Fin Clause WHERE ---

    if (ob_get_level() > 0) { ob_end_clean(); }

    // --- Headers CSV ---
    $filename = 'export_' . $type . '_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF"; // BOM UTF-8

    $output = fopen('php://output', 'w');
    if ($output === false) { throw new Exception("Impossible d'ouvrir php://output."); }

    // --- Export LOGS ---
    if ($type === 'logs') {
        $log_table = 'event_logs'; $id_col = 'id'; $message_col = 'message'; $date_col = 'event_date';
        $where_clause_logs = str_replace($date_col_placeholder, $date_col, $where_base_template);
        $where_clause_logs = str_replace("$user_col_placeholder LIKE :user", "$message_col LIKE :user", $where_clause_logs);
        $sql_logs = "SELECT {$id_col}, {$message_col}, {$date_col} FROM {$log_table} {$where_clause_logs} ORDER BY {$date_col} DESC";

        $stmt = $pdo->prepare($sql_logs);
        foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
        $stmt->execute();

        fputcsv($output, ['ID Log', 'Utilisateur', 'Date Événement', 'Message'], ';');
        $rowCount = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rowCount++;
            $date_fr = format_date_fr($row[$date_col] ?? '');
            $message = $row[$message_col] ?? '';
            $user_extracted = 'Système'; $message_content = $message;
            if (preg_match('/^([a-zA-Z0-9_.-]+):\s*(.*)$/s', $message, $matches)) {
                 $user_extracted = trim($matches[1]); $message_content = trim($matches[2]);
            }
            fputcsv($output, [$row[$id_col] ?? '', $user_extracted, $date_fr, $message_content], ';');
        }
        if ($rowCount === 0) fputcsv($output, ['Aucun log trouvé pour les filtres sélectionnés.'], ';');

    // --- Export CHECKLISTS ---
    } else {
        $checklist_table = 'checklists_history';
        $id_col = 'id'; $user_col = 'user'; $type_col = 'type';
        $date_col = 'date_validation'; $status_col = 'status';
        $comm_col = 'commentaire'; $json_col = 'data_json';

        $where_clause_checklists = str_replace($date_col_placeholder, $date_col, $where_base_template);
        $where_clause_checklists = str_replace("$user_col_placeholder LIKE :user", "$user_col = :user", $where_clause_checklists);
        if (!empty($filter_user) && isset($params[':user'])) { $params[':user'] = $filter_user; }

        $sql_checklists = "SELECT {$id_col}, {$user_col}, {$type_col}, {$date_col}, {$status_col}, {$comm_col}, {$json_col}
                           FROM {$checklist_table} {$where_clause_checklists} ORDER BY {$date_col} DESC";

        $stmt = $pdo->prepare($sql_checklists);
        foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
        $stmt->execute();

        fputcsv($output, [
            'N° Contrôle', 'Type', 'Utilisateur', 'Date du contrôle', 'Résultat global', 'Commentaire',
            'Items Vérifiés OK', 'Items Manquants', 'Items Défaillants', 'Détails Anomalies'
        ], ';');

        $stmt_prod_name = $pdo->prepare("SELECT nom FROM produits WHERE id = ?");
        $stmt_ambu_name = $pdo->prepare("SELECT nom FROM ambu_items WHERE id = ?");

        $rowCount = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rowCount++;
            $date_fr = format_date_fr($row[$date_col] ?? '');
            $checklist_type = $row[$type_col] ?? 'DPS';

            $status_lower = strtolower($row[$status_col] ?? '');
            switch ($status_lower) {
                case 'complete': $status_fr = 'Complète'; break;
                case 'with_issues': $status_fr = 'Avec problèmes'; break;
                case 'incomplete': $status_fr = 'Incomplète'; break;
                default: $status_fr = ucfirst($status_lower ?: 'Inconnu'); break;
            }

            $ok_items = 0; $missing_items = 0; $failing_items = 0; $anomalies_list = [];
            if (!empty($row[$json_col])) {
                try {
                    $items_data = json_decode($row[$json_col], true);
                    if (is_array($items_data)) {
                        foreach ($items_data as $itemId => $details) {
                             $itemName = $details['nom'] ?? 'Item ID '.$itemId;
                             $etat = $details['etat'] ?? 'vide';
                             $isOkChecked = isset($details['ok']) && $details['ok'] === true;

                             if ($etat === 'vide' && $isOkChecked) { $ok_items++; }
                             elseif ($etat === 'manquant') { $missing_items++; $anomalies_list[] = $itemName . ' (MANQUANT)'; }
                             elseif ($etat === 'defaillant') { $failing_items++; $anomalies_list[] = $itemName . ' (DÉFAILLANT)'; }
                        }
                    }
                } catch (Exception $e) { $anomalies_list[] = "Erreur lecture JSON"; }
            }
            $anomalies_str = !empty($anomalies_list) ? implode(' | ', $anomalies_list) : '-';

            fputcsv($output, [
                $row[$id_col] ?? '', $checklist_type, $row[$user_col] ?? '', $date_fr,
                $status_fr, ($row[$comm_col] ?? '-') ?: '-', $ok_items,
                $missing_items, $failing_items, $anomalies_str
            ], ';');
        }
        if ($rowCount === 0) fputcsv($output, ['Aucune checklist trouvée pour les filtres sélectionnés.'], ';');
    }

} catch (PDOException $e) {
    if (ob_get_level() > 0) { ob_end_clean(); }
    error_log("Erreur PDO Export CSV: " . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erreur Base de Données lors de l'exportation. Veuillez consulter les logs du serveur.\n";
        echo "Message: " . $e->getMessage() . "\n";
    } else { echo "\n\n!-- ERREUR PDO APRES DEBUT SORTIE CSV --!"; }

} catch (Exception $e) {
     if (ob_get_level() > 0) { ob_end_clean(); }
    error_log("Erreur Générale Export CSV: " . $e->getMessage());
     if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erreur Serveur lors de l'exportation. Veuillez consulter les logs du serveur.\n";
        echo "Message: " . $e->getMessage() . "\n";
     } else { echo "\n\n!-- ERREUR SERVEUR APRES DEBUT SORTIE CSV --!"; }

} finally {
    if (is_resource($output)) { fclose($output); }
}

exit;
?>
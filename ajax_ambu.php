<?php
// ajax_ambu.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once 'access_control.php';
require_login('editor');
require_once 'config.php'; // Inclut db_connection.php via config.php

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Erreur: Connexion BDD non disponible.']);
    exit;
}

$action = $_POST['action'] ?? '';

// Action pour lister les sous-zones d'une zone
if ($action === 'list_sous_zones') {
    $zone_id = filter_input(INPUT_POST, 'zone_id', FILTER_VALIDATE_INT); // Validation plus sûre
    if (!$zone_id || $zone_id <= 0) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Zone ID invalide ou manquant.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, nom, color, ordre FROM ambu_sous_zones WHERE zone_id = ? ORDER BY ordre, nom");
        $stmt->execute([$zone_id]);
        $sous_zones = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; // Assurer un array
        echo json_encode(['success'=>true,'sous_zones'=> $sous_zones], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        error_log("Erreur AJAX list_sous_zones: ".$e->getMessage());
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Erreur serveur lors de la récupération des sous-zones.']);
        exit;
    }
}

// Si aucune action valide n'est trouvée
http_response_code(400);
echo json_encode(['success'=>false,'message'=>'Action inconnue ou non spécifiée.']);
?>
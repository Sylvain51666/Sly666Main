<?php
// =====================================================
// access_control.php - NOUVEAU Fichier de sécurité
// CORRIGÉ: Accolade en trop supprimée à la fin
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_connection.php';
require_once 'functions.php';

// --- CHARGEMENT DES PARAMÈTRES GLOBAUX ---
// On charge les paramètres une seule fois ici
global $app_settings;
if (!isset($app_settings)) {
    try {
        $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $app_settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        $app_settings = [];
        error_log("Erreur critique: Impossible de charger les settings: " . $e->getMessage());
        die("Erreur critique de l'application. Impossible de charger la configuration.");
    }
}

/**
 * Fonction de sécurité principale.
 * Vérifie si l'utilisateur doit être connecté et s'il a le bon rôle.
 *
 * @param string $required_role ('user', 'editor', 'admin')
 */
function require_login($required_role = 'user') {
    global $app_settings;
    
    // 1. Vérifier si le login est requis
    $login_required = $app_settings['app_requireLogin'] ?? '0';
    if ($login_required === '0' && $required_role === 'user') {
        // Le login n'est pas requis pour les 'user' (dps.php)
        // L'utilisateur est "Anonyme"
        if (!is_logged_in()) {
             $_SESSION['username'] = 'Anonyme';
             $_SESSION['role'] = 'user';
        }
        return true;
    }
    
    // 2. Le login est requis (admin ou paramètre activé)
    if (!is_logged_in()) {
        redirect('login.php');
    }
    
    // 3. Vérifier le rôle
    $user_role = $_SESSION['role'] ?? 'user';
    
    // Hiérarchie des rôles
    $roles_hierarchy = [
        'user'  => 1,
        'editor' => 2,
        'admin'  => 3
    ];
    
    $user_level = $roles_hierarchy[$user_role] ?? 1;
    $required_level = $roles_hierarchy[$required_role] ?? 1;
    
    if ($user_level < $required_level) {
        // Pas la permission
        log_event($GLOBALS['pdo'], $_SESSION['username'], "Accès non autorisé à une page (Niveau requis: $required_role)");
        // Rediriger vers sa page par défaut
        redirect_to_dashboard();
    }
    
    return true;
}

/**
 * Vérifie si l'utilisateur a au moins un certain rôle.
 * N'affiche pas d'erreur, retourne juste true/false.
 * (Parfait pour afficher/cacher des boutons)
 *
 * @param string $role ('editor', 'admin')
 * @return bool
 */
function check_permission($role = 'editor') {
    if (!is_logged_in()) {
        return false;
    }
    
    $user_role = $_SESSION['role'] ?? 'user';
    
    $roles_hierarchy = [
        'user'  => 1,
        'editor' => 2,
        'admin'  => 3
    ];
    
    $user_level = $roles_hierarchy[$user_role] ?? 1;
    $required_level = $roles_hierarchy[$role] ?? 1;
    
    return $user_level >= $required_level;
}

// L'accolade en trop a été supprimée ici
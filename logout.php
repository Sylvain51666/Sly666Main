<?php
// =====================================================
// logout.php - NOUVEAU Script de déconnexion
// =====================================================

session_start();
require_once 'db_connection.php';
require_once 'functions.php';

$username = $_SESSION['username'] ?? 'Inconnu';
log_event($pdo, $username, 'Déconnexion réussie.');

// Détruire toutes les variables de session
$_SESSION = array();

// Détruire la session
session_destroy();

// Rediriger vers la page de connexion
header("Location: login.php");
exit;
?>
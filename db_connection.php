<?php
// =====================================================
// db_connection.php - MODIFIÉ pour InfinityFree
// Charge functions.php
// AMÉLIORÉ: Affiche une page d'erreur HTML stylisée
// =====================================================

// Configuration de la base de données
$host = 'sql300.infinityfree.com'; // NOUVEAU HÔTE
$dbname = 'if0_40119301_checklistdps_01'; // NOUVELLE BDD
$username_db = 'if0_40119301'; // NOUVEL UTILISATEUR
$password_db = 'G1jkh6PQZOycsEg'; // NOUVEAU MOT DE PASSE
$charset = 'utf8mb4';

// Options PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// DSN (Data Source Name)
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

try {
    // Créer l'objet PDO
    $pdo = new PDO($dsn, $username_db, $password_db, $options);
} catch (PDOException $e) {
    // Gérer les erreurs de connexion
    error_log("Erreur de connexion PDO: " . $e->getMessage());

    // Afficher un message d'erreur HTML stylisé à l'utilisateur
    // On ne peut pas "die()" simplement, il faut une page complète.
    echo '<!DOCTYPE html>';
    echo '<html lang="fr">';
    echo '<head>';
    echo '    <meta charset="UTF-8">';
    echo '    <meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '    <title>Erreur de Connexion</title>';
    
    // On lie le style.css principal pour la cohérence
    $cssVersion = file_exists('style.css') ? filemtime('style.css') : '1';
    echo '    <link rel="stylesheet" href="style.css?v=' . $cssVersion . '">';
    echo '</head>';
    echo '<body>'; // Le CSS gérera le mode clair/sombre par défaut
    
    // On utilise la classe .container
    echo '    <div class="container" style="margin-top: 5vh;">';
    
    // On ajoute le logo pour la cohérence
    echo '        <div class="logo" style="text-align: center; margin-bottom: 1.5rem;">';
    echo '            <img src="https://static.wixstatic.com/media/f643e0_e7cb955f4fa14191bb309fafe25a6567~mv2.png/v1/fill/w_202,h_186,al_c,lg_1,q_85,enc_avif,quality_auto/UD%20LOGO.png" alt="Logo UD" style="max-width: 120px;">';
    echo '        </div>';
    
    // On utilise les classes .alert et .alert-error
    echo '        <div class="alert alert-error">';
    echo '            <h2 style="margin-top: 0; margin-bottom: 0.5rem; font-size: 1.25rem;">Erreur Critique</h2>';
    
    // Le message modifié comme demandé
    echo '            <p style="margin-bottom: 0.5rem;">Erreur de connexion à la base de données. Veuillez réessayer plus tard.</p>';
    echo '            <strong>Contacter l\'admin (le Boss !) Sylvain D.</strong>';
    echo '        </div>';
    echo '    </div>';
    echo '</body>';
    echo '</html>';
    
    // On arrête l'exécution du script après avoir affiché l'erreur.
    exit;
}

// Inclure le fichier de fonctions
// Il est maintenant requis par la plupart des fichiers
require_once 'functions.php';
?>
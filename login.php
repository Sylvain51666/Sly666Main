<?php
// =====================================================
// login.php - MODIFIÉ
// - Ajout bouton "Accès Anonyme" conditionnel
// - CORRIGÉ V2 (HUB): Le bouton anonyme redirige vers index.php
// =====================================================

// On a besoin de 'access_control.php' pour charger les settings
require_once 'access_control.php';

// Si déjà connecté, rediriger vers la bonne page
if (is_logged_in()) {
    redirect_to_dashboard();
}

// On récupère les settings globaux chargés par access_control.php
global $app_settings;
$login_required = $app_settings['app_requireLogin'] ?? '1'; // 1 (requis) par défaut si non trouvé

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login_user'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = 'Le nom d\'utilisateur et le mot de passe sont requis.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $user['password'] === hash('sha256', $password)) {

                // Stocker les infos de session
                $_SESSION['loggedin'] = true;
                $_SESSION['userid'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role']; // RÔLE

                log_event($pdo, $username, 'Connexion réussie.');

                // Rediriger vers la bonne page (admin.php ou index.php/dps.php)
                redirect_to_dashboard();

            } else {
                $error = 'Nom d\'utilisateur ou mot de passe incorrect.';
                log_event($pdo, $username, 'Échec de la connexion.');
            }
        } catch (PDOException $e) {
            $error = "Erreur de base de données. Veuillez réessayer.";
            error_log("Erreur Login: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - CheckList DPS</title>
    <link rel="stylesheet" href="style.css?v=<?= file_exists('style.css') ? filemtime('style.css') : '1' ?>">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: var(--bg-body);
        }
        .login-container {
            max-width: 400px;
            width: 100%;
            padding: var(--spacing-xl);
            background: var(--bg-container);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-hover);
            animation: fadeIn 0.5s ease-out;
        }
        .login-container .logo {
            text-align: center;
            margin-bottom: var(--spacing-lg);
        }
        .login-container .logo img {
            max-width: 120px;
        }
        .login-container h1 {
            text-align: center;
            font-size: 1.5rem;
            margin-bottom: var(--spacing-lg);
            color: var(--text-primary);
        }
        .form-group {
            margin-bottom: var(--spacing-md);
        }
        .form-group label {
            display: block;
            margin-bottom: var(--spacing-sm);
            font-weight: 600;
            color: var(--text-secondary);
        }
        .form-group input[type="text"],
        .form-group input[type="password"] {
            width: 100%;
            padding: var(--spacing-md);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-body);
            color: var(--text-primary);
            font-size: 1rem;
            transition: border-color var(--transition-fast);
        }
        .form-group input[type="text"]:focus,
        .form-group input[type="password"]:focus {
            outline: none;
            border-color: var(--color-primary);
        }
        .login-button {
            width: 100%;
            padding: var(--spacing-md);
            border: none;
            border-radius: var(--radius-md);
            background: var(--color-primary);
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color var(--transition-fast);
            margin-bottom: var(--spacing-md); /* Espace pour le bouton en dessous */
        }
        .login-button:hover {
            background: var(--color-primary-hover);
        }

        /* Bouton d'accès anonyme */
        .guest-button {
            width: 100%;
            padding: var(--spacing-sm) var(--spacing-md);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-pochette);
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: block;
            transition: all var(--transition-fast);
        }
        .guest-button:hover {
            background: var(--bg-body);
            color: var(--text-primary);
            border-color: var(--color-primary);
        }

        .error-message {
            background: #fee;
            color: #c00;
            border: 1px solid #c00;
            border-left: 4px solid var(--color-danger);
            padding: var(--spacing-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--spacing-lg);
            text-align: center;
            animation: shake 0.5s ease-out;
        }
    </style>
</head>
<body class="dark-mode"> <div class="login-container">
        <div class="logo">
            <img src="https://static.wixstatic.com/media/f643e0_e7cb955f4fa14191bb309fafe25a6567~mv2.png/v1/fill/w_202,h_186,al_c,lg_1,q_85,enc_avif,quality_auto/UD%20LOGO.png" alt="Logo UD">
        </div>
        <h1>Connexion CheckList</h1>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="username">Nom d'utilisateur</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" name="login_user" class="login-button">Se connecter</button>
        </form>

        <?php // Bouton conditionnel pointant vers index.php ?>
        <?php if ($login_required === '0'): ?>
            <a href="index.php" class="guest-button">
                Accéder à la checklist (Mode Anonyme)
            </a>
        <?php endif; ?>

    </div>
</body>
</html>
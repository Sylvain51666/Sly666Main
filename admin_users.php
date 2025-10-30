<?php
// =====================================================
// admin_users.php - MODIFIÉ
// Traduction FR, Explication des rôles
// =====================================================

require_once 'access_control.php';
require_login('admin'); // Seuls les admins peuvent accéder

$feedback = ['type' => '', 'message' => ''];

// Traitement des actions POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- AJOUTER UN UTILISATEUR ---
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $role = trim($_POST['role']);

        if (empty($username) || empty($password) || empty($role)) {
            $feedback = ['type' => 'error', 'message' => 'Tous les champs sont requis pour ajouter un utilisateur.'];
        } elseif (!in_array($role, ['user', 'editor', 'admin'])) {
            $feedback = ['type' => 'error', 'message' => 'Rôle non valide.'];
        } else {
            try {
                // Vérifier si l'utilisateur existe déjà
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $feedback = ['type' => 'error', 'message' => 'Ce nom d\'utilisateur existe déjà.'];
                } else {
                    // Créer l'utilisateur
                    $hashed_password = hash('sha256', $password);
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                    $stmt->execute([$username, $hashed_password, $role]);
                    log_event($pdo, $_SESSION['username'], "Ajout de l'utilisateur '$username' avec le rôle '$role'.");
                    $feedback = ['type' => 'success', 'message' => 'Utilisateur ' . htmlspecialchars($username) . ' ajouté avec succès.'];
                }
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()];
            }
        }
    }

    // --- SUPPRIMER UN UTILISATEUR ---
    if (isset($_POST['delete_user'])) {
        $user_id = (int)$_POST['user_id'];

        if ($user_id === $_SESSION['userid']) {
            $feedback = ['type' => 'error', 'message' => 'Vous ne pouvez pas supprimer votre propre compte.'];
        } else {
            try {
                $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();

                if ($user) {
                    $stmt_delete = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt_delete->execute([$user_id]);
                    log_event($pdo, $_SESSION['username'], "Suppression de l'utilisateur '" . $user['username'] . "' (ID: $user_id).");
                    $feedback = ['type' => 'success', 'message' => 'Utilisateur ' . htmlspecialchars($user['username']) . ' supprimé.'];
                } else {
                    $feedback = ['type' => 'error', 'message' => 'Utilisateur non trouvé.'];
                }
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()];
            }
        }
    }

    // --- MODIFIER UN MOT DE PASSE ---
    if (isset($_POST['update_password'])) {
        $user_id = (int)$_POST['user_id'];
        $new_password = trim($_POST['new_password']);

        if (empty($new_password)) {
            $feedback = ['type' => 'error', 'message' => 'Le nouveau mot de passe ne peut pas être vide.'];
        } else {
            try {
                $hashed_password = hash('sha256', $new_password);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                log_event($pdo, $_SESSION['username'], "Modification du mot de passe pour l'utilisateur ID: $user_id.");
                $feedback = ['type' => 'success', 'message' => 'Mot de passe mis à jour.'];
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()];
            }
        }
    }

    // --- MODIFIER UN RÔLE ---
    if (isset($_POST['update_role'])) {
        $user_id = (int)$_POST['user_id'];
        $new_role = trim($_POST['new_role']);

        if (!in_array($new_role, ['user', 'editor', 'admin'])) {
            $feedback = ['type' => 'error', 'message' => 'Rôle non valide.'];
        } elseif ($user_id === $_SESSION['userid'] && $new_role !== 'admin') {
            $feedback = ['type' => 'error', 'message' => 'Vous ne pouvez pas retirer vos propres droits d\'administrateur.'];
        } else {
             try {
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$new_role, $user_id]);
                log_event($pdo, $_SESSION['username'], "Modification du rôle pour l'utilisateur ID: $user_id en '$new_role'.");
                $feedback = ['type' => 'success', 'message' => 'Rôle mis à jour.'];
            } catch (PDOException $e) {
                $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()];
            }
        }
    }
}

// Récupérer la liste des utilisateurs
try {
    $users = $pdo->query("SELECT id, username, role FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
    $feedback = ['type' => 'error', 'message' => 'Impossible de charger la liste des utilisateurs: ' . $e->getMessage()];
}

// --- NOUVEAU : Mapping des rôles pour l'affichage ---
$roles_map = [
    'user' => 'Utilisateur',
    'editor' => 'Éditeur',
    'admin' => 'Administrateur'
];
// --- FIN NOUVEAU ---

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Utilisateurs - Admin</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-section {
            background: var(--bg-pochette);
            padding: var(--spacing-lg);
            border-radius: var(--radius-lg);
            margin-bottom: var(--spacing-xl);
        }
        .admin-section h2 {
            margin-top: 0;
            margin-bottom: var(--spacing-lg);
            border-bottom: 2px solid var(--border-color);
            padding-bottom: var(--spacing-md);
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: var(--spacing-md);
            align-items: flex-end;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-sm);
        }
        label {
            font-weight: 600;
            color: var(--text-secondary);
        }
        input[type="text"], input[type="password"], select {
            padding: var(--spacing-md);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-container);
            color: var(--text-primary);
            font-size: 1rem;
        }
        button {
            padding: var(--spacing-md);
            border: none;
            border-radius: var(--radius-md);
            background: var(--color-primary);
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color var(--transition-fast);
        }
        button:hover {
            background: var(--color-primary-hover);
        }
        .btn-danger {
            background: var(--color-danger);
        }
        .btn-danger:hover {
            background: #b91c1c;
        }
        .user-list {
            margin-top: var(--spacing-lg);
        }
        .user-item {
            display: grid;
            grid-template-columns: 1fr auto; /* Ajusté pour mieux aligner */
            gap: var(--spacing-md);
            padding: var(--spacing-md);
            border-bottom: 1px solid var(--border-color);
            align-items: center;
        }
        .user-item:last-child {
            border-bottom: none;
        }
        .user-info {
            font-size: 1.1rem;
            display: flex; /* Pour aligner le nom et le badge */
            align-items: center;
            gap: var(--spacing-md);
        }
        .role-badge { /* Nouveau style pour le badge */
            font-weight: 600;
            padding: 3px 8px;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            text-transform: capitalize;
        }
        .role-user { background: var(--color-info); color: white; }
        .role-editor { background: var(--color-warning); color: #4d2b01; } /* Couleur texte ajustée */
        .role-admin { background: var(--color-danger); color: white; }

        .user-actions {
            display: flex;
            gap: var(--spacing-sm);
            flex-wrap: wrap;
            justify-content: flex-end; /* Aligner à droite */
        }
        .user-actions form {
            display: flex;
            gap: var(--spacing-sm);
        }
        .user-actions select { padding: var(--spacing-sm); font-size: 0.9rem; }
        .user-actions input[type="password"] { padding: var(--spacing-sm); font-size: 0.9rem; max-width: 150px; }
        .user-actions button { padding: var(--spacing-sm); font-size: 0.9rem; }

        /* NOUVEAU : Bloc d'explication des rôles */
        .roles-explanation {
            background: var(--bg-container);
            border: 1px dashed var(--border-color);
            padding: var(--spacing-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--spacing-xl);
        }
        .roles-explanation ul {
            list-style: none;
            padding-left: 0;
        }
        .roles-explanation li {
            margin-bottom: var(--spacing-sm);
        }
        .roles-explanation strong {
            display: inline-block;
            min-width: 120px;
        }

        @media (max-width: 768px) {
            .user-item {
                grid-template-columns: 1fr; /* Une seule colonne sur mobile */
            }
            .user-actions {
                justify-content: flex-start; /* Aligner à gauche sur mobile */
                margin-top: var(--spacing-sm);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="text-align: center; margin-bottom: 2rem;">👥 Gestion des Utilisateurs</h1>

        <?php if (!empty($feedback['message'])): ?>
            <div class="alert alert-<?= $feedback['type'] === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($feedback['message']) ?>
            </div>
        <?php endif; ?>

        <div class="roles-explanation">
            <h2>Comprendre les Rôles :</h2>
            <ul>
                <li><strong><span class="role-badge role-user">Utilisateur</span> :</strong> Peut se connecter (si requis) et valider la checklist. N'a accès à aucune page d'administration.</li>
                <li><strong><span class="role-badge role-editor">Éditeur</span> :</strong> Accès Utilisateur + Panneau Admin (Dashboard, Logs, Gestion Items & Images).</li>
                <li><strong><span class="role-badge role-admin">Administrateur</span> :</strong> Accès total, y compris cette page (Gestion Utilisateurs) et les Paramètres Experts.</li>
            </ul>
        </div>
        <div class="admin-section">
            <h2>Ajouter un utilisateur</h2>
            <form action="admin_users.php" method="POST" class="form-grid">
                <div class="form-group">
                    <label for="username">Nom d'utilisateur</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="role">Rôle</label>
                    <select id="role" name="role" required>
                        <option value="user">Utilisateur</option>
                        <option value="editor">Éditeur</option>
                        <option value="admin">Administrateur</option>
                    </select>
                </div>
                <button type="submit" name="add_user">Ajouter</button>
            </form>
        </div>

        <div class="admin-section">
            <h2>Utilisateurs actuels (<?= count($users) ?>)</h2>
            <div class="user-list">
                <?php foreach ($users as $user): ?>
                <div class="user-item">
                    <div class="user-info">
                        <?= htmlspecialchars($user['username']) ?>
                        <span class="role-badge role-<?= htmlspecialchars($user['role']) ?>">
                            <?= htmlspecialchars($roles_map[$user['role']] ?? $user['role']) ?>
                        </span>
                    </div>

                    <div class="user-actions">
                        <form action="admin_users.php" method="POST">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <select name="new_role" onchange="this.form.submit()" title="Changer le rôle">
                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Utilisateur</option>
                                <option value="editor" <?= $user['role'] === 'editor' ? 'selected' : '' ?>>Éditeur</option>
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                            <input type="hidden" name="update_role" value="1">
                        </form>

                        <form action="admin_users.php" method="POST" onsubmit="return confirm('Mettre à jour le mot de passe pour cet utilisateur ?');">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <input type="password" name="new_password" placeholder="Nouveau MDP" required title="Nouveau mot de passe">
                            <button type="submit" name="update_password" title="Valider le nouveau mot de passe">OK</button>
                        </form>

                        <?php if ($user['id'] !== $_SESSION['userid']): // Ne pas afficher le bouton pour soi-même ?>
                        <form action="admin_users.php" method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer <?= htmlspecialchars(addslashes($user['username'])) ?> ? Cette action est irréversible.');">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <button type="submit" name="delete_user" class="btn-danger" title="Supprimer cet utilisateur">Supprimer</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="actions">
            <button onclick="window.location.href='admin.php';">← Retour Admin</button>
        </div>
    </div>
</body>
</html>
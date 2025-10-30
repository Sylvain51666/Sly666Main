<?php
// =====================================================
// admin.php - MODIFIÉ
// Panel d'admin sécurisé, sans login.
// - MODIFIÉ V2: Bouton retour vers index.php
// =====================================================

// On inclut notre nouveau garde de sécurité
require_once 'access_control.php';

// On vérifie si un login est requis (rôle 'editor' au minimum)
require_login('editor');

// On vérifie si l'utilisateur est admin pour les cartes spéciales
$is_admin = check_permission('admin');

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - CheckList DPS</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-xl);
        }
        .admin-card {
            background: var(--bg-pochette);
            padding: var(--spacing-lg);
            border-radius: var(--radius-lg);
            text-align: center;
            border: 1px solid var(--border-color);
            transition: all var(--transition-base);
            text-decoration: none;
            color: var(--text-primary);
        }
        .admin-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--color-primary);
        }
        .admin-card-icon {
            font-size: 3rem;
            line-height: 1;
            margin-bottom: var(--spacing-md);
        }
        .admin-card h2 {
            font-size: 1.25rem;
            margin-bottom: var(--spacing-sm);
        }
        .admin-card p {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .admin-header h1 {
            margin: 0;
        }
        .user-info {
            background: var(--bg-pochette);
            padding: var(--spacing-sm) var(--spacing-md);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
        }
        .user-info span {
            font-weight: 600;
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            background: var(--color-primary);
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="admin-header">
            <h1>Panel d'Administration</h1>
            <div class="user-info">
                Connecté: <b><?= htmlspecialchars($_SESSION['username']) ?></b>
                (Rôle: <span><?= htmlspecialchars($_SESSION['role']) ?></span>)
                | <a href="logout.php">Déconnexion</a>
            </div>
        </div>

        <div class="admin-grid">
            
            <a href="admin_dashboard.php" class="admin-card">
                <div class="admin-card-icon">📊</div>
                <h2>Dashboard</h2>
                <p>Voir les statistiques et l'historique récent.</p>
            </a>
            
            <a href="admin_checklist.php" class="admin-card">
                <div class="admin-card-icon">📋</div>
                <h2>Gestion des Items</h2>
                <p>Ajouter, modifier ou supprimer des produits.</p>
            </a>
            
            <a href="admin_img.php" class="admin-card">
                <div class="admin-card-icon">🖼️</div>
                <h2>Gestion des Images</h2>
                <p>Mettre à jour les miniatures des produits.</p>
            </a>
            
            <a href="admin_log.php" class="admin-card">
                <div class="admin-card-icon">📝</div>
                <h2>Logs d'Événements</h2>
                <p>Consulter le journal des actions du site.</p>
            </a>
            
            <?php if ($is_admin): ?>
            <a href="admin_users.php" class="admin-card" style="border-left: 4px solid var(--color-warning);">
                <div class="admin-card-icon">👥</div>
                <h2>Gestion Utilisateurs</h2>
                <p>Créer, modifier et gérer les comptes.</p>
            </a>
            
            <a href="admin_advanced.php" class="admin-card" style="border-left: 4px solid var(--color-danger);">
                <div class="admin-card-icon">⚙️</div>
                <h2>Paramètres Experts</h2>
                <p>Gérer les emails et l'interface (admin seul).</p>
            </a>
            <?php endif; ?>
            
        </div>
        
        <div class="actions">
            <button onclick="window.location.href='index.php';">← Retour Accueil</button>
        </div>
    </div>
</body>
</html>
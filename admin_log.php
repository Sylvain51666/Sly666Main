<?php
// =====================================================
// admin_log.php - MODIFIÉ V5.1
// - Correction: Suppression des commentaires visibles
// - CORRIGÉ V5: Logique de filtrage beaucoup plus précise
// - CORRIGÉ V5.1: Filtres 'ajout', 'modif', 'suppr' assouplis
// =====================================================

require_once 'access_control.php';
require_login('editor'); // Rôle 'editor' au minimum
require_once 'functions.php'; // Pour format_date_fr

// Pagination
$logsPerPage = defined('LOGS_PER_PAGE') ? LOGS_PER_PAGE : 15;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $logsPerPage;

// Filtre par type (CORRIGÉ V5)
$filter = $_GET['filter'] ?? 'all';
$filter_label = $filter; // Sera mis à jour dans le switch
$countWhereClause = "";
$logsWhereClause = "";
$params = [];

try {
    // === NOUVELLE LOGIQUE DE FILTRAGE PLUS PRÉCISE ===
    switch ($filter) {
        case 'all':
            $filter_label = 'Tous';
            // Aucune clause WHERE nécessaire
            break;
        case 'DPS':
            $countWhereClause = " WHERE message LIKE :filter_tag";
            $logsWhereClause = " WHERE message LIKE :filter_tag";
            $params[':filter_tag'] = '%[SAC DPS]%'; // Tag spécifique
            $filter_label = 'Sacs DPS';
            break;
        case 'AMBU':
            $countWhereClause = " WHERE message LIKE :filter_tag";
            $logsWhereClause = " WHERE message LIKE :filter_tag";
            $params[':filter_tag'] = '%[AMBU]%'; // Tag spécifique
            $filter_label = 'Ambulance';
            break;
        case 'Connexion':
            // Cherche le motif exact pour éviter "Déconnexion"
            $countWhereClause = " WHERE message LIKE :filter_tag";
            $logsWhereClause = " WHERE message LIKE :filter_tag";
            $params[':filter_tag'] = '%: Connexion réussie.%';
            $filter_label = 'Connexions';
            break;
        case 'Déconnexion':
            $countWhereClause = " WHERE message LIKE :filter_tag";
            $logsWhereClause = " WHERE message LIKE :filter_tag";
            $params[':filter_tag'] = '%: Déconnexion réussie.%'; // Texte spécifique
            $filter_label = 'Déconnexions';
            break;
        case 'ajout':
             // CORRIGÉ V5.1: Cherche 'Ajout ' (avec espace) ou 'Création'
            $countWhereClause = " WHERE message LIKE :filter_ajout OR message LIKE :filter_creation";
            $logsWhereClause = " WHERE message LIKE :filter_ajout OR message LIKE :filter_creation";
            $params[':filter_ajout'] = '%Ajout %';
            $params[':filter_creation'] = '%Création%';
            $filter_label = 'Ajouts';
            break;
        case 'Modification':
            // CORRIGÉ V5.1: Regroupe modifs générales, MAJ, réorganisation (sans les ':')
            $countWhereClause = " WHERE (message LIKE :filter_modif OR message LIKE :filter_maj OR message LIKE :filter_reorg) AND message NOT LIKE :filter_img";
            $logsWhereClause = " WHERE (message LIKE :filter_modif OR message LIKE :filter_maj OR message LIKE :filter_reorg) AND message NOT LIKE :filter_img";
            $params[':filter_modif'] = '%Modification%';
            $params[':filter_maj'] = '%MàJ%';
            $params[':filter_reorg'] = '%Réorganisation%';
            $params[':filter_img'] = '%Mise à jour de l\'image%';
            $filter_label = 'Modifications';
            break;
        case 'Mise à jour': // Filtre spécifique pour les images
            $countWhereClause = " WHERE message LIKE :filter_tag";
            $logsWhereClause = " WHERE message LIKE :filter_tag";
            $params[':filter_tag'] = '%Mise à jour de l\'image%'; // Texte spécifique aux images
            $filter_label = 'MàJ Image';
            break;
        case 'Suppression':
            // CORRIGÉ V5.1: Cherche 'Suppression ' (avec espace)
            $countWhereClause = " WHERE message LIKE :filter_tag";
            $logsWhereClause = " WHERE message LIKE :filter_tag";
            $params[':filter_tag'] = '%Suppression %';
            $filter_label = 'Suppressions';
            break;
        default:
            // Sécurité : si filtre inconnu, on affiche tout
            $filter = 'all';
            $filter_label = 'Tous';
            break;
    }
    // === FIN NOUVELLE LOGIQUE ===


    // Compter le total (inchangé)
    $countSql = "SELECT COUNT(*) FROM event_logs" . $countWhereClause;
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalLogs = $stmtCount->fetchColumn();
    $totalPages = ceil($totalLogs / $logsPerPage);

    // Récupérer les logs pour la page actuelle (inchangé)
    $logsSql = "SELECT * FROM event_logs" . $logsWhereClause . " ORDER BY event_date DESC LIMIT :limit OFFSET :offset";
    $stmtLogs = $pdo->prepare($logsSql);
    // Lier les paramètres dynamiquement
    foreach ($params as $key => $value) {
        $stmtLogs->bindValue($key, $value);
    }
    $stmtLogs->bindValue(':limit', $logsPerPage, PDO::PARAM_INT);
    $stmtLogs->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtLogs->execute();
    $logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur PDO dans admin_log.php: " . $e.getMessage());
    die("Erreur lors de la récupération des logs. Veuillez vérifier les logs du serveur.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs d'Événements - Admin</title>
    <link rel="stylesheet" href="style.css?v=<?= file_exists('style.css') ? filemtime('style.css') : '1' ?>">
    <style>
        .log-item {
            background: var(--bg-container);
            padding: var(--spacing-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--spacing-sm);
            border-left: 4px solid var(--color-info); /* Couleur par défaut (utilisée pour DPS) */
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all var(--transition-fast);
        }
        .log-item:hover {
            transform: translateX(4px);
            box-shadow: var(--shadow);
        }
        .log-message {
            flex: 1;
            font-size: 0.9375rem;
            word-break: break-word; /* Permet au texte long de passer à la ligne */
        }
        .log-date {
            font-size: 0.875rem;
            color: var(--text-secondary);
            white-space: nowrap;
            margin-left: var(--spacing-md);
        }
        /* Couleurs spécifiques par type de log */
        .log-connexion { border-left-color: var(--color-success); }
        .log-deconnexion { border-left-color: var(--color-warning); }
        .log-suppression { border-left-color: var(--color-danger); }
        .log-ajout { border-left-color: #10b981; } /* Vert un peu différent */
        .log-modification { border-left-color: #3b82f6; } /* Bleu info */
        .log-maj-image { border-left-color: #a78bfa; } /* Violet clair pour images */
        .log-ambu { border-left-color: #8B5CF6; } /* Violet pour AMBU */
        .log-dps { border-left-color: #f59e0b; } /* Orange pour DPS */

        .filter-bar {
            display: flex;
            gap: var(--spacing-sm);
            margin-bottom: var(--spacing-lg);
            flex-wrap: wrap;
        }
        .filter-btn {
            padding: var(--spacing-sm) var(--spacing-md);
            border: 2px solid var(--border-color);
            background: var(--bg-container);
            color: var(--text-primary);
            border-radius: var(--radius-md);
            cursor: pointer;
            font-weight: 600;
            transition: all var(--transition-fast);
            text-decoration: none;
        }
        .filter-btn:hover, .filter-btn.active {
            border-color: var(--color-primary);
            background: var(--color-primary);
            color: white;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: var(--spacing-sm);
            margin-top: var(--spacing-xl);
            flex-wrap: wrap;
        }
        .page-btn {
            padding: var(--spacing-sm) var(--spacing-md);
            border: 2px solid var(--border-color);
            background: var(--bg-container);
            color: var(--text-primary);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: all var(--transition-fast);
            text-decoration: none;
        }
        .page-btn:hover, .page-btn.active {
            border-color: var(--color-primary);
            background: var(--color-primary);
            color: white;
        }

        .stats-bar {
            background: var(--bg-pochette);
            padding: var(--spacing-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--spacing-lg);
            display: flex;
            justify-content: space-around;
            text-align: center;
            flex-wrap: wrap;
            gap: var(--spacing-sm);
        }
        .stat-item {
            flex: 1;
            min-width: 100px;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-primary);
        }
        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        @media (max-width: 600px) {
            .log-item {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--spacing-xs);
            }
            .log-date {
                margin-left: 0;
                font-size: 0.8125rem;
                width: 100%;
                text-align: left;
            }
            .stats-bar {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="text-align: center; margin-bottom: 2rem;">📝 Logs d'Événements</h1>

        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-value"><?= $totalLogs ?></div>
                <div class="stat-label">Événements (<?= htmlspecialchars($filter_label) ?>)</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= $totalPages ?></div>
                <div class="stat-label">Pages</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= count($logs) ?></div>
                <div class="stat-label">Affichés</div>
            </div>
        </div>

        <div class="filter-bar">
            <a href="?page=1&filter=all" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">
                📋 Tous
            </a>
            <a href="?page=1&filter=DPS" class="filter-btn <?= $filter === 'DPS' ? 'active' : '' ?>">
                🎒 Sacs DPS
            </a>
            <a href="?page=1&filter=AMBU" class="filter-btn <?= $filter === 'AMBU' ? 'active' : '' ?>">
                🚑 Ambulance
            </a>
            <a href="?page=1&filter=Connexion" class="filter-btn <?= $filter === 'Connexion' ? 'active' : '' ?>">
                🔐 Connexions
            </a>
            <a href="?page=1&filter=Déconnexion" class="filter-btn <?= $filter === 'Déconnexion' ? 'active' : '' ?>">
                🚪 Déconnexions
            </a>
            <a href="?page=1&filter=ajout" class="filter-btn <?= $filter === 'ajout' ? 'active' : '' ?>">
                ➕ Ajouts
            </a>
            <a href="?page=1&filter=Modification" class="filter-btn <?= $filter === 'Modification' ? 'active' : '' ?>">
                ✏️ Modifications
            </a>
             <a href="?page=1&filter=Mise à jour" class="filter-btn <?= $filter === 'Mise à jour' ? 'active' : '' ?>">
                🖼️ MàJ Image
            </a>
            <a href="?page=1&filter=Suppression" class="filter-btn <?= $filter === 'Suppression' ? 'active' : '' ?>">
                🗑️ Suppressions
            </a>
        </div>

        <div class="pochette">
            <?php if (empty($logs)): ?>
            <p style="text-align: center; color: var(--text-secondary);">Aucun événement à afficher pour ce filtre.</p>
            <?php else: ?>
                <?php foreach ($logs as $log):
                    // Attribution des classes CSS (légèrement modifiée V5)
                    $class = 'log-item';
                    $message = $log['message']; // Pour faciliter les tests

                    if (strpos($message, ': Connexion réussie.') !== false) {
                         $class .= ' log-connexion';
                    } elseif (strpos($message, ': Déconnexion réussie.') !== false) {
                         $class .= ' log-deconnexion';
                    } elseif (strpos($message, 'Suppression ') !== false) { // Corrigé V5.1
                         $class .= ' log-suppression';
                    } elseif (strpos($message, 'Ajout ') !== false || strpos($message, 'Création') !== false) { // Corrigé V5.1
                         $class .= ' log-ajout';
                    } elseif (strpos($message, 'Mise à jour de l\'image') !== false) {
                         $class .= ' log-maj-image'; // Classe spécifique pour images
                    } elseif (strpos($message, 'Modification') !== false || strpos($message, 'MàJ') !== false || strpos($message, 'Réorganisation') !== false) { // Corrigé V5.1
                         $class .= ' log-modification'; // Modifications générales
                    } elseif (strpos($message, '[AMBU]') !== false) {
                        $class .= ' log-ambu'; // Spécifique AMBU
                    } elseif (strpos($message, '[SAC DPS]') !== false) {
                        $class .= ' log-dps'; // Spécifique DPS
                    } else {
                        // Pas de couleur spécifique si non catégorisé
                    }
                ?>
                <div class="<?= $class ?>">
                    <div class="log-message">
                        <?= htmlspecialchars($message) ?>
                    </div>
                    <div class="log-date">
                        <?= format_date_fr($log['event_date']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?page=1&filter=<?= urlencode($filter) ?>" class="page-btn">« Premier</a>
            <a href="?page=<?= $page - 1 ?>&filter=<?= urlencode($filter) ?>" class="page-btn">‹ Précédent</a>
            <?php endif; ?>

            <?php
            // Logique de pagination pour afficher quelques pages autour de la page actuelle
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);

            if ($startPage > 1) {
                // Lien vers la première page si on est loin
                 if ($startPage > 2) echo '<span class="page-btn" style="cursor: default; border: none; background: none;">...</span>';
            }

            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
            <a href="?page=<?= $i ?>&filter=<?= urlencode($filter) ?>"
               class="page-btn <?= $i === $page ? 'active' : '' ?>">
                <?= $i ?>
            </a>
            <?php endfor;

            if ($endPage < $totalPages) {
                // Lien vers la dernière page si on est loin
                if ($endPage < $totalPages - 1) echo '<span class="page-btn" style="cursor: default; border: none; background: none;">...</span>';
            }
            ?>

            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&filter=<?= urlencode($filter) ?>" class="page-btn">Suivant ›</a>
            <a href="?page=<?= $totalPages ?>&filter=<?= urlencode($filter) ?>" class="page-btn">Dernier »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="actions" style="margin-top: 3rem;">
            <button onclick="window.location.href='admin.php';">← Retour Admin</button>
            <button onclick="window.location.href='export_csv.php';">💾 Export CSV</button>
        </div>
    </div>
</body>
</html>
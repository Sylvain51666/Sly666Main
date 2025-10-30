<?php
// =====================================================
// admin_dashboard.php - MODIFIÉ V3.1
// - Ajout du garde de sécurité
// - CORRIGÉ V3: Correction MAJEURE des requêtes SQL.
//   Les ID d'items (produits vs ambu_items) se chevauchent.
//   Les requêtes doivent OBLIGATOIREMENT joindre checklists_history
//   pour connaître le TYPE ('DPS' ou 'AMBU') AVANT
//   de joindre la table d'items correspondante.
// - AJOUT V3: Ajout des emojis 🚑 et 🎒 (corrigé)
// - AJOUT V3: Ajout de la tuile "Cette Année"
// =====================================================

require_once 'access_control.php';
require_login('editor'); // Rôle 'editor' au minimum
require_once 'functions.php'; // Pour format_date_fr

try {
    // Stats globales
    $stmt = $pdo->query("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'complete' THEN 1 ELSE 0 END) as completes,
               SUM(CASE WHEN status = 'with_issues' THEN 1 ELSE 0 END) as with_issues
        FROM checklists_history
    ");
    $statsGlobal = $stmt->fetch(PDO::FETCH_ASSOC);

    // Stats de l'année (NOUVEAU)
    $stmt_annee = $pdo->query("
        SELECT COUNT(*) as total_annee
        FROM checklists_history
        WHERE YEAR(date_validation) = YEAR(CURRENT_DATE())
    ");
    $statsAnnee = $stmt_annee->fetch(PDO::FETCH_ASSOC);

    // Stats du mois
    $stmt_mois = $pdo->query("
        SELECT COUNT(*) as total_mois
        FROM checklists_history
        WHERE MONTH(date_validation) = MONTH(CURRENT_DATE())
        AND YEAR(date_validation) = YEAR(CURRENT_DATE())
    ");
    $statsMois = $stmt_mois->fetch(PDO::FETCH_ASSOC);


    // Dernière checklist (inchangé, la requête est bonne)
    $stmt = $pdo->query("
        SELECT * FROM checklists_history
        ORDER BY date_validation DESC LIMIT 1
    ");
    $lastChecklist = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- CORRECTION V3: Tooltip Dernière Checklist ---
    $lastChecklistIssues = '';
    if ($lastChecklist && $lastChecklist['status'] === 'with_issues') {
        
        $checklist_type = $lastChecklist['type']; // 'DPS' ou 'AMBU'
        $checklist_id = $lastChecklist['id'];

        // Requête corrigée: utilise le type pour joindre la bonne table
        $stmt_issues = $pdo->prepare("
            SELECT
                CASE
                    WHEN ? = 'AMBU' THEN A.nom
                    ELSE P.nom
                END AS nom,
                H.status_constate
            FROM
                checklist_history_items H
            LEFT JOIN
                produits P ON H.produit_id = P.id
            LEFT JOIN
                ambu_items A ON H.produit_id = A.id
            WHERE
                H.history_id = ?
                AND H.status_constate != 'vide'
                AND ( (? = 'DPS' AND P.id IS NOT NULL) OR (? = 'AMBU' AND A.id IS NOT NULL) )
            ORDER BY nom
        ");
        $stmt_issues->execute([$checklist_type, $checklist_id, $checklist_type, $checklist_type]);
        $issues = $stmt_issues->fetchAll(PDO::FETCH_ASSOC);

        $manquants = [];
        $defaillants = [];
        foreach ($issues as $item) {
            if (empty($item['nom'])) {
                $item['nom'] = 'Item inconnu'; // Fallback
            }
            if ($item['status_constate'] === 'manquant') {
                $manquants[] = $item['nom'];
            } else { // defaillant
                $defaillants[] = $item['nom'];
            }
        }
        if (!empty($manquants)) {
            $lastChecklistIssues .= "Manquant(s):\n" . implode("\n", array_map('htmlspecialchars', $manquants));
        }
        if (!empty($defaillants)) {
            $lastChecklistIssues .= (!empty($manquants) ? "\n\n" : "") . "Défaillant(s):\n" . implode("\n", array_map('htmlspecialchars', $defaillants));
        }

        if (!empty($lastChecklist['commentaire'])) {
            $lastChecklistIssues .= (!empty($lastChecklistIssues) ? "\n\n" : "") . "Commentaire:\n" . htmlspecialchars(trim($lastChecklist['commentaire']));
        }
    }
    // --- FIN CORRECTION V3 ---

    // --- CORRECTION V3: Items Fréquemment Manquants ---
    // Requête corrigée: jointure sur checklists_history pour obtenir le type
    // et GROUP BY sur le nom ET le type.
    $stmt_top = $pdo->query("
        SELECT
            CASE
                WHEN CH.type = 'AMBU' THEN A.nom
                ELSE P.nom
            END AS item_nom,
            CH.type AS item_type,
            COUNT(H.id) AS total_manquant
        FROM
            checklist_history_items H
        JOIN
            checklists_history CH ON H.history_id = CH.id
        LEFT JOIN
            produits P ON H.produit_id = P.id AND CH.type = 'DPS'
        LEFT JOIN
            ambu_items A ON H.produit_id = A.id AND CH.type = 'AMBU'
        WHERE
            H.status_constate IN ('manquant', 'defaillant')
            AND COALESCE(P.id, A.id) IS NOT NULL
        GROUP BY
            item_nom, item_type
        ORDER BY
            total_manquant DESC
        LIMIT 5
    ");
    $topMissing = $stmt_top->fetchAll(PDO::FETCH_ASSOC); // Doit être FETCH_ASSOC
    // --- FIN CORRECTION V3 ---

    // Checklists récentes (inchangé, la requête est bonne)
    $stmt = $pdo->query("
        SELECT * FROM checklists_history
        ORDER BY date_validation DESC
        LIMIT 10
    ");
    $recentChecklists = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur PDO admin_dashboard: " . $e->getMessage()); // Log l'erreur
    die("Erreur lors de la récupération des données du dashboard. Veuillez consulter les logs.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - CheckList DPS & Ambu</title>
    <link rel="stylesheet" href="style.css?v=<?= file_exists('style.css') ? filemtime('style.css') : '1' ?>">
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); /* Min-width réduit */
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-xl);
        }
        .stat-card {
            background: var(--bg-pochette);
            padding: var(--spacing-lg);
            border-radius: var(--radius-lg);
            border-left: 5px solid var(--color-primary);
            box-shadow: var(--shadow);
            transition: all var(--transition-base);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
        }
        .stat-card h3 {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-sm);
            text-transform: uppercase;
        }
        .stat-card .value {
            font-size: 2.25rem; /* Augmenté */
            font-weight: 700;
            color: var(--text-primary);
        }
        .checklist-list {
            background: var(--bg-pochette);
            padding: var(--spacing-lg);
            border-radius: var(--radius-lg);
            margin-bottom: var(--spacing-lg);
        }
        .checklist-item {
            padding: var(--spacing-md);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: var(--spacing-md); /* Ajout d'un gap */
        }
        .checklist-item:last-child {
            border-bottom: none;
        }
        /* Style pour le côté gauche (nom, date, etc.) */
        .checklist-item-info {
            flex: 1; /* Prend l'espace disponible */
            min-width: 0; /* Permet au texte de passer à la ligne */
        }
        .checklist-item-info strong {
            font-size: 1.1rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 99px; /* Badge arrondi */
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap; /* Empêche le badge de passer à la ligne */
            flex-shrink: 0; /* Empêche le badge de rétrécir */
        }
        .status-complete { background: #d1fae5; color: #065f46; }
        .status-issues { background: #fef3c7; color: #92400e; }
        body.dark-mode .status-complete { background: #065f46; color: #d1fae5; }
        body.dark-mode .status-issues { background: #92400e; color: #fef3c7; }

        /* Correction pour le tooltip (style.css) */
        .tooltip-badge[data-tooltip]:hover::after {
             white-space: pre-wrap !important; /* Force le retour à la ligne */
        }

    </style>
</head>
<body>
    <div class="container">
        <h1 style="text-align: center; margin-bottom: 2rem;">📊 Dashboard CheckLists</h1>

        <div class="dashboard-grid">
            <div class="stat-card" style="border-left-color: var(--color-primary);">
                <h3>Total Vérifications</h3>
                <div class="value"><?= $statsGlobal['total'] ?? 0 ?></div>
            </div>
            <div class="stat-card" style="border-left-color: var(--color-info);">
                <h3>Cette Année</h3>
                <div class="value"><?= $statsAnnee['total_annee'] ?? 0 ?></div>
            </div>
            <div class="stat-card" style="border-left-color: var(--color-success);">
                <h3>Ce Mois-ci</h3>
                <div class="value"><?= $statsMois['total_mois'] ?? 0 ?></div>
            </div>
            <div class="stat-card" style="border-left-color: var(--color-danger);">
                <h3>Avec Problèmes</h3>
                <div class="value"><?= $statsGlobal['with_issues'] ?? 0 ?></div>
            </div>
        </div>

        <?php if ($lastChecklist): ?>
        <div class="checklist-list">
            <h2>🕐 Dernière Vérification</h2>
            <div class="checklist-item">
                <div class="checklist-item-info">
                    <?php
                    // AJOUT EMOJI (Corrigé V3.1)
                    $emoji = ($lastChecklist['type'] === 'AMBU') ? '🚑' : '🎒';
                    ?>
                    <strong><?= $emoji ?> <?= htmlspecialchars($lastChecklist['user']) ?></strong><br>
                    <small><?= format_date_fr($lastChecklist['date_validation']) ?></small>
                </div>

                <?php
                $is_complete = $lastChecklist['status'] === 'complete';
                $badge_class = $is_complete ? 'status-complete' : 'status-issues';
                $badge_text = $is_complete ? 'Complète' : 'Avec problèmes';

                $tooltip_attrs = '';
                // Utiliser la variable $lastChecklistIssues (corrigée V3)
                if (!$is_complete && !empty($lastChecklistIssues)) {
                    $badge_class .= ' tooltip-badge';
                    $tooltip_attrs = ' tabindex="0" data-tooltip="' . $lastChecklistIssues . '"';
                }
                ?>
                <span class="status-badge <?= $badge_class ?>"<?= $tooltip_attrs ?>>
                    <?= $badge_text ?>
                </span>

            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($topMissing)): ?>
        <div class="checklist-list">
            <h2>⚠️ Items Fréquemment Manquants/Défaillants (Top 5)</h2>
            <?php foreach ($topMissing as $item): ?>
            <div class="checklist-item">
                <div class="checklist-item-info">
                    <?php
                    // AJOUT EMOJI (Corrigé V3.1)
                    $emoji = ($item['item_type'] === 'AMBU') ? '🚑' : '🎒';
                    ?>
                    <span><?= $emoji ?> <?= htmlspecialchars($item['item_nom']) ?></span>
                </div>
                <span class="status-badge status-issues"><?= $item['total_manquant'] ?>x</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="checklist-list">
            <h2>📋 Vérifications Récentes (10 dernières)</h2>
            <?php if (empty($recentChecklists)): ?>
                <p style="text-align: center; color: var(--text-secondary);">Aucune vérification récente.</p>
            <?php else: ?>
                <?php foreach ($recentChecklists as $checklist): ?>
                <div class="checklist-item">
                    <div class="checklist-item-info">
                        <?php
                        // AJOUT EMOJI (Corrigé V3.1)
                        $emoji = ($checklist['type'] === 'AMBU') ? '🚑' : '🎒';
                        ?>
                        <strong>#<?= $checklist['id'] ?> - <?= $emoji ?> - <?= htmlspecialchars($checklist['user']) ?></strong><br>

                        <small><?= format_date_fr($checklist['date_validation']) ?></small>
                    </div>

                    <?php
                    $is_complete_loop = $checklist['status'] === 'complete';
                    $badge_class_loop = $is_complete_loop ? 'status-complete' : 'status-issues';
                    $badge_text_loop = $is_complete_loop ? 'OK' : 'Problèmes';

                    $tooltip_attrs_loop = '';
                    $tooltip_text_loop = ''; // Réinitialiser

                    if (!$is_complete_loop) {
                        // --- CORRECTION V3: Tooltip Boucle ---
                        $checklist_type_loop = $checklist['type'];
                        $checklist_id_loop = $checklist['id'];
                        
                        $stmt_issues_loop = $pdo->prepare("
                            SELECT
                                CASE
                                    WHEN ? = 'AMBU' THEN A.nom
                                    ELSE P.nom
                                END AS nom,
                                H.status_constate
                            FROM
                                checklist_history_items H
                            LEFT JOIN
                                produits P ON H.produit_id = P.id
                            LEFT JOIN
                                ambu_items A ON H.produit_id = A.id
                            WHERE
                                H.history_id = ?
                                AND H.status_constate != 'vide'
                                AND ( (? = 'DPS' AND P.id IS NOT NULL) OR (? = 'AMBU' AND A.id IS NOT NULL) )
                            ORDER BY nom
                        ");
                        $stmt_issues_loop->execute([$checklist_type_loop, $checklist_id_loop, $checklist_type_loop, $checklist_type_loop]);
                        $issues_loop = $stmt_issues_loop->fetchAll(PDO::FETCH_ASSOC);

                        $manquants_loop = [];
                        $defaillants_loop = [];
                        foreach ($issues_loop as $item_loop) {
                            if (empty($item_loop['nom'])) {
                               $item_loop['nom'] = 'Item inconnu'; // Fallback
                            }
                            if ($item_loop['status_constate'] === 'manquant') {
                                $manquants_loop[] = $item_loop['nom'];
                            } else {
                                $defaillants_loop[] = $item_loop['nom'];
                            }
                        }
                        // --- FIN CORRECTION V3 ---

                        if (!empty($manquants_loop)) {
                            $tooltip_text_loop .= "Manquant(s):\n" . implode("\n", array_map('htmlspecialchars', $manquants_loop));
                        }
                        if (!empty($defaillants_loop)) {
                            $tooltip_text_loop .= (!empty($manquants_loop) ? "\n\n" : "") . "Défaillant(s):\n" . implode("\n", array_map('htmlspecialchars', $defaillants_loop));
                        }

                        if (!empty($checklist['commentaire'])) {
                             $tooltip_text_loop .= (!empty($tooltip_text_loop) ? "\n\n" : "") . "Commentaire:\n" . htmlspecialchars(trim($checklist['commentaire']));
                        }

                        if (!empty($tooltip_text_loop)) {
                             $badge_class_loop .= ' tooltip-badge';
                             $tooltip_attrs_loop = ' tabindex="0" data-tooltip="' . $tooltip_text_loop . '"';
                        }
                    }
                    ?>
                    <span class="status-badge <?= $badge_class_loop ?>"<?= $tooltip_attrs_loop ?>>
                        <?= $badge_text_loop ?>
                    </span>

                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="actions">
            <button onclick="window.location.href='admin.php';">← Retour Admin</button>
            <button onclick="window.location.href='index.php';">🏠 Accueil</button>
            <button onclick="window.location.href='export_csv.php';">💾 Export CSV</button>
        </div>
    </div>
</body>
</html>
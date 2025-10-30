<?php
// =====================================================
// admin_advanced.php - MODIFIÉ
// - Remplacement WYSIWYG par CKEditor 4
// - Ajout d'une alerte de sécurité
// - VERSION COMPLÈTE: Ajout de tous les paramètres configurables
// - AJOUT V8: Textes du popup de bienvenue
// =====================================================

require_once 'access_control.php';
require_login('admin'); // Seuls les admins peuvent accéder

$feedback = ['type' => '', 'message' => ''];

// Traitement des actions POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");

        foreach ($_POST as $key => $value) {
            if (preg_match('/^(app_|css_|email_)/', $key)) {
                if ($key === 'email_template_html') {
                     // Nettoyage spécifique pour CKEditor
                     $value = preg_replace('/<p>(?:<br[^>]*>|&nbsp;|\s)*<\/p>\s*/i', '', $value);
                     $value = trim($value);
                     $stmt->execute([$value, $key]);
                } elseif ($key === 'app_text_welcomeMessage') {
                     // Nettoyage simple pour le message de bienvenue (autorise <br>)
                     $value = trim($value);
                     // On pourrait ajouter un nettoyage plus poussé si besoin (strip_tags sauf <br>)
                     $stmt->execute([$value, $key]);
                } else {
                    // Nettoyage standard pour les autres
                    $value = sanitize_input($value);
                    $stmt->execute([$value, $key]);
                }
            }
        }
        $pdo->commit();
        log_event($pdo, $_SESSION['username'], 'Mise à jour des paramètres avancés.');
        $feedback = ['type' => 'success', 'message' => 'Paramètres mis à jour avec succès.'];
        unset($GLOBALS['app_settings']); // Vider cache
    } catch (PDOException $e) {
        $pdo->rollBack();
        $feedback = ['type' => 'error', 'message' => 'Erreur BDD: ' . $e->getMessage()];
    }
}

// Récupérer tous les paramètres
try {
    $settings_map_full = $pdo->query("SELECT setting_key, setting_value, description FROM settings")
                             ->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    if (!isset($GLOBALS['app_settings'])) {
        $GLOBALS['app_settings'] = [];
        foreach ($settings_map_full as $key => $data) {
             $GLOBALS['app_settings'][$key] = $data['setting_value'];
        }
    }
} catch (PDOException $e) {
    $settings_map_full = []; $GLOBALS['app_settings'] = [];
    $feedback = ['type' => 'error', 'message' => 'Impossible de charger les paramètres: ' . $e->getMessage()];
}

// Fonctions helper
function get_setting($key, $default = '') {
    global $settings_map_full;
    return $settings_map_full[$key]['setting_value'] ?? $default;
}
function get_description($key, $default = '') {
    global $settings_map_full;
    $desc = $settings_map_full[$key]['description'] ?? $default;
    return !empty($desc) ? $desc : $default;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres Experts - Admin</title>
    <link rel="stylesheet" href="style.css?v=<?= file_exists('style.css') ? filemtime('style.css') : '1' ?>">
    <script src="https://cdn.ckeditor.com/4.16.2/standard/ckeditor.js"></script>
    <style>
        /* Styles CSS (inchangés par rapport à la version précédente) */
        .admin-section { background: var(--bg-pochette); padding: var(--spacing-lg); border-radius: var(--radius-lg); margin-bottom: var(--spacing-xl); }
        .admin-section h2 { margin-top: 0; margin-bottom: var(--spacing-lg); border-bottom: 2px solid var(--border-color); padding-bottom: var(--spacing-md); }
        .admin-section h3 { margin-top: var(--spacing-lg); margin-bottom: var(--spacing-md); color: var(--color-primary); font-size: 1.1rem; }
        .form-group { margin-bottom: var(--spacing-lg); }
        .form-group label { display: block; font-weight: 600; color: var(--text-primary); margin-bottom: var(--spacing-sm); }
        .form-group small { display: block; color: var(--text-secondary); font-size: 0.875rem; margin-top: var(--spacing-xs); font-style: italic; }
        input[type="text"], input[type="number"], input[type="color"], select, textarea, textarea#email_template_html { width: 100%; padding: var(--spacing-md); border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-container); color: var(--text-primary); font-size: 1rem; box-sizing: border-box; } /* Ajout textarea générique */
        input[type="number"] { max-width: 150px; }
        input[type="color"] { padding: var(--spacing-sm); height: 50px; max-width: 100px; }
        textarea { min-height: 80px; resize: vertical; font-family: inherit; } /* Style pour les textarea simples */
        .cke_chrome { border: 2px solid var(--border-color) !important; border-radius: var(--radius-md) !important; box-shadow: none !important; }
        .cke_top { background: var(--bg-pochette) !important; border-bottom: 1px solid var(--border-color) !important; }
        .cke_contents { background: var(--bg-container) !important; }
        .submit-button { padding: var(--spacing-md) var(--spacing-xl); border: none; border-radius: var(--radius-md); background: var(--color-primary); color: white; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background-color var(--transition-fast); }
        .submit-button:hover { background: var(--color-primary-hover); }
        .variables-list { background: var(--bg-container); border: 1px dashed var(--border-color); padding: var(--spacing-md); border-radius: var(--radius-md); margin-bottom: var(--spacing-sm); font-size: 0.9rem; }
        .variables-list code { background: var(--bg-body); padding: 2px 6px; border-radius: var(--radius-sm); color: var(--color-danger); font-weight: 600; white-space: nowrap; margin: 0 2px; display: inline-block; }
        .cke_error { display: none !important; }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="text-align: center; margin-bottom: 2rem;">⚙️ Paramètres Experts</h1>
        <div class="alert alert-warning" style="margin-bottom: var(--spacing-lg);">
             ⚠️ <strong>Attention !</strong> Modifications réservées aux administrateurs confirmés.
        </div>
        <?php if (!empty($feedback['message'])): ?>
            <div class="alert alert-<?= $feedback['type'] === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($feedback['message']) ?>
            </div>
        <?php endif; ?>

        <form action="admin_advanced.php" method="POST">
            <div class="admin-section">
                <h2>Général</h2>
                <div class="form-group">
                    <label for="app_requireLogin">Connexion obligatoire pour checklist</label>
                    <select id="app_requireLogin" name="app_requireLogin">
                        <option value="0" <?= get_setting('app_requireLogin', '0') === '0' ? 'selected' : '' ?>>Non (anonyme)</option>
                        <option value="1" <?= get_setting('app_requireLogin', '0') === '1' ? 'selected' : '' ?>>Oui</option>
                    </select>
                    <small><?= htmlspecialchars(get_description('app_requireLogin', "Si 'Non', les utilisateurs non connectés peuvent valider sous le nom 'Anonyme'.")) ?></small>
                </div>
                 <div class="form-group">
                    <label for="app_maxCommentLength">Longueur max. commentaire</label>
                    <input type="number" id="app_maxCommentLength" name="app_maxCommentLength"
                           value="<?= htmlspecialchars(get_setting('app_maxCommentLength', '1000')) ?>" min="50" max="5000">
                    <small><?= htmlspecialchars(get_description('app_maxCommentLength', 'Nombre max. de caractères dans le champ commentaire.')) ?></small>
                </div>
            </div>

            <div class="admin-section">
                <h2>Emails de Notification</h2>
                <div class="form-group">
                    <label for="email_destination">Destinataire(s)</label>
                    <input type="text" id="email_destination" name="email_destination"
                           value="<?= htmlspecialchars(get_setting('email_destination')) ?>">
                    <small><?= htmlspecialchars(get_description('email_destination', 'Adresse(s) email (séparées par une virgule si plusieurs).')) ?></small>
                </div>
                <div class="form-group">
                    <label for="email_sendCondition">Envoyer l'email...</label>
                    <select id="email_sendCondition" name="email_sendCondition">
                        <option value="all" <?= get_setting('email_sendCondition', 'issues_only') === 'all' ? 'selected' : '' ?>>Toujours</option>
                        <option value="issues_only" <?= get_setting('email_sendCondition', 'issues_only') === 'issues_only' ? 'selected' : '' ?>>Seulement si problèmes</option>
                    </select>
                    <small><?= htmlspecialchars(get_description('email_sendCondition', "Condition pour déclencher l'envoi de l'email.")) ?></small>
                </div>
                <div class="form-group">
                    <label for="email_template_html">Modèle de l'email (HTML)</label>
                    <div class="variables-list">
                        <p style="margin-bottom: var(--spacing-sm);">Variables disponibles :</p>
                        <code>[USER]</code> <code>[DATE_FR]</code> <code>[STATUS]</code> <code>[CHECKLIST_ID]</code>
                        <code>[COMMENTAIRE]</code> <br><code>[ITEMS_MANQUANTS]</code> <code>[ITEMS_DEFAILLANTS]</code>
                    </div>
                    <textarea id="email_template_html" name="email_template_html">
                        <?= htmlspecialchars(get_setting('email_template_html')) ?>
                    </textarea>
                    <small><?= htmlspecialchars(get_description('email_template_html', 'Template HTML pour l\'email. Utilisez les variables ci-dessus.')) ?></small>
                </div>
            </div>

             <div class="admin-section">
                <h2>Interface Utilisateur</h2>
                <h3>Durées (en millisecondes, 1000 = 1 seconde)</h3>
                 <div class="form-group">
                    <label for="app_toastDuration">Durée Toast Standard</label>
                    <input type="number" id="app_toastDuration" name="app_toastDuration"
                           value="<?= htmlspecialchars(get_setting('app_toastDuration', '1500')) ?>" min="500" step="100">
                    <small><?= htmlspecialchars(get_description('app_toastDuration', 'Durée affichage toast info (thème, mode vue...).')) ?></small>
                </div>
                 <div class="form-group">
                    <label for="app_toastValidationDuration">Durée Toast Validation (Erreur/Alerte)</label>
                    <input type="number" id="app_toastValidationDuration" name="app_toastValidationDuration"
                           value="<?= htmlspecialchars(get_setting('app_toastValidationDuration', '5000')) ?>" min="1000" step="100">
                    <small><?= htmlspecialchars(get_description('app_toastValidationDuration', 'Durée affichage toast si validation échoue (champs manquants...).')) ?></small>
                </div>
                 <div class="form-group">
                    <label for="app_toastSuccessDuration">Durée Toast Succès/Échec Envoi</label>
                    <input type="number" id="app_toastSuccessDuration" name="app_toastSuccessDuration"
                           value="<?= htmlspecialchars(get_setting('app_toastSuccessDuration', '8000')) ?>" min="1000" step="100">
                    <small><?= htmlspecialchars(get_description('app_toastSuccessDuration', 'Durée affichage toast final après appui sur "Envoyer".')) ?></small>
                </div>
                <div class="form-group">
                    <label for="app_autoSaveInterval">Intervalle Sauvegarde Brouillon</label>
                    <input type="number" id="app_autoSaveInterval" name="app_autoSaveInterval"
                           value="<?= htmlspecialchars(get_setting('app_autoSaveInterval', '30000')) ?>" min="5000" step="1000">
                    <small><?= htmlspecialchars(get_description('app_autoSaveInterval', 'Fréquence de sauvegarde auto. du formulaire en cours.')) ?></small>
                </div>

                <h3>Textes des Toasts</h3>
                 <?php
                 $toast_texts = [
                    'app_text_themeChangedTitle' => 'Titre: Thème changé',
                    'app_text_themeChangedMessage' => 'Msg: Thème changé ({themeName})',
                    'app_text_viewModeChangedTitle' => 'Titre: Mode affichage changé',
                    'app_text_viewModeChangedMessageLarge' => 'Msg: Mode Large activé',
                    'app_text_viewModeChangedMessageNormal' => 'Msg: Mode Standard activé',
                    'app_text_validationErrorTitle' => 'Titre: Validation impossible',
                    'app_text_validationErrorMessage' => 'Msg: Validation impossible',
                    'app_text_validationWarningTitle' => 'Titre: Champs manquants',
                    'app_text_validationWarningMessage' => 'Msg: Champs manquants ({items})',
                    'app_text_successTitle' => 'Titre: Succès envoi',
                    'app_text_successMessagePrefix' => 'Msg: Succès envoi (début)',
                    'app_text_successNetworkErrorTitle' => 'Titre: Échec envoi (local ok)',
                    'app_text_successNetworkErrorMessage' => 'Msg: Échec envoi (local ok)',
                    'app_text_issuesDetected' => 'Texte: "Problèmes détectés:"',
                    'app_text_issuesMissing' => 'Texte: "Manquants:"',
                    'app_text_issuesFailing' => 'Texte: "Défaillants:"',
                    'app_text_resetTitle' => 'Titre: Formulaire réinitialisé',
                    'app_text_resetMessage' => 'Msg: Formulaire réinitialisé',
                    'app_text_draftRestoredTitle' => 'Titre: Brouillon restauré',
                    'app_text_draftRestoredMessage' => 'Msg: Brouillon restauré',
                 ];
                 foreach ($toast_texts as $key => $label): ?>
                 <div class="form-group">
                     <label for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                     <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= htmlspecialchars(get_setting($key)) ?>">
                     <small><?= htmlspecialchars(get_description($key)) ?></small>
                 </div>
                 <?php endforeach; ?>
                 
                 <h3>Textes du Modal de Réinitialisation</h3>
                  <?php
                 $modal_texts = [
                    'app_text_resetModalTitle' => 'Titre du modal',
                    'app_text_resetModalMessage' => 'Message/Question du modal',
                    'app_text_resetModalConfirmButton' => 'Bouton Confirmation (Oui)',
                    'app_text_resetModalCancelButton' => 'Bouton Annulation (Non)',
                 ];
                 foreach ($modal_texts as $key => $label): ?>
                 <div class="form-group">
                     <label for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                     <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= htmlspecialchars(get_setting($key)) ?>">
                     <small><?= htmlspecialchars(get_description($key)) ?></small>
                 </div>
                 <?php endforeach; ?>

                 <h3>Textes Divers</h3>
                 <div class="form-group">
                    <label for="app_text_welcomeTitle">Titre Popup Bienvenue</label>
                    <input type="text" id="app_text_welcomeTitle" name="app_text_welcomeTitle" value="<?= htmlspecialchars(get_setting('app_text_welcomeTitle')) ?>">
                    <small><?= htmlspecialchars(get_description('app_text_welcomeTitle', 'Titre principal affiché dans le popup au chargement.')) ?></small>
                 </div>
                 <div class="form-group">
                    <label for="app_text_welcomeMessage">Message Popup Bienvenue (HTML autorisé)</label>
                    <textarea id="app_text_welcomeMessage" name="app_text_welcomeMessage" rows="3"><?= htmlspecialchars(get_setting('app_text_welcomeMessage')) ?></textarea>
                    <small><?= htmlspecialchars(get_description('app_text_welcomeMessage', 'Message sous le titre (ex: `<br>` pour saut de ligne).')) ?></small>
                 </div>
                 <h3>Apparence (CSS)</h3>
                 <?php foreach ($settings_map_full as $key => $setting): ?>
                    <?php if (strpos($key, 'css_') === 0): ?>
                    <div class="form-group">
                        <label for="<?= $key ?>"><?= htmlspecialchars(ucfirst(str_replace(['css_', '_'], ['', ' '], $key))) ?></label>
                        <?php if (strpos($key, 'color') !== false): ?>
                        <input type="color" id="<?= $key ?>" name="<?= $key ?>" value="<?= htmlspecialchars($setting['setting_value']) ?>">
                        <?php else: ?>
                        <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= htmlspecialchars($setting['setting_value']) ?>">
                        <?php endif; ?>
                        <small><?= htmlspecialchars(get_description($key, 'Variable CSS: --' . str_replace('_', '-', substr($key, 4)) )) ?></small>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="actions" style="margin-top: var(--spacing-xl);">
                <button type="submit" class="submit-button">💾 Enregistrer tous les paramètres</button>
            </div>
        </form>

        <div class="actions" style="margin-top: 1rem;">
            <button onclick="window.location.href='admin.php';" style="background: var(--text-secondary);">← Retour Admin</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', (event) => {
            CKEDITOR.replace('email_template_html', {
                height: 400,
                 toolbarGroups: [ // Toolbar simplifiée
                    { name: 'basicstyles', groups: [ 'basicstyles', 'cleanup' ] },
                    { name: 'paragraph', groups: [ 'list', 'indent', 'blocks', 'align', 'paragraph' ] },
                    { name: 'links', groups: [ 'links' ] },
                    { name: 'insert', groups: [ 'insert' ] },
                    { name: 'styles', groups: [ 'styles' ] },
                    { name: 'colors', groups: [ 'colors' ] },
                    { name: 'document', groups: [ 'mode', 'document', 'doctools' ] },
                    { name: 'about', groups: [ 'about' ] }
                ],
                removeButtons: 'Save,NewPage,Preview,Print,Templates,Cut,Copy,Paste,PasteText,PasteFromWord,Find,Replace,SelectAll,Scayt,Form,Checkbox,Radio,TextField,Textarea,Select,Button,ImageButton,HiddenField,Subscript,Superscript,RemoveFormat,CopyFormatting,Outdent,Indent,CreateDiv,Blockquote,Language,BidiRtl,BidiLtr,Anchor,Flash,Smiley,SpecialChar,PageBreak,Iframe,Font,FontSize,ShowBlocks,About,Styles,Format',
                 uiColor: getComputedStyle(document.body).getPropertyValue('--bg-pochette').trim() || '#f9fafb'
             });
        });
    </script>
</body>
</html>
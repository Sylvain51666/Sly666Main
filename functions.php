<?php
// =====================================================
// functions.php - VERSION FINALE (COMPLÈTE)
// Qualité Image Max + Correction bug fonction image
// =====================================================

/**
 * Écrire un événement dans la base de données
 * CORRIGÉ : Force l'horodatage en UTC depuis PHP pour éviter
 * les problèmes de fuseau horaire du serveur SQL.
 */
function log_event($pdo, $user, $message) {
    try {
        // Préparer l'insertion en spécifiant la date
        $stmt = $pdo->prepare("INSERT INTO event_logs (message, event_date) VALUES (?, ?)");
        
        $full_message = "$user: $message";
        
        // Créer un horodatage UTC_NOW() en PHP
        $utc_now = new DateTime("now", new DateTimeZone("UTC"));
        $utc_now_str = $utc_now->format('Y-m-d H:i:s');
        
        // Exécuter la requête avec l'heure UTC
        $stmt->execute([$full_message, $utc_now_str]);
        
    } catch (PDOException $e) {
        error_log("Erreur log_event: " . $e->getMessage());
    }
}

/**
 * Nettoyer et valider une entrée
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Vérifier si l'utilisateur est connecté
 */
function is_logged_in() {
    return isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['role']);
}

/**
 * Rediriger vers une page
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Redirige l'utilisateur vers la bonne page après connexion.
 */
function redirect_to_dashboard() {
    if (!is_logged_in()) {
        redirect('login.php');
        return;
    }
    $role = $_SESSION['role'] ?? 'user';
    if ($role === 'admin' || $role === 'editor') {
        redirect('admin.php');
    } else {
        redirect('dps.php');
    }
}

/**
 * Formate une date (stockée en UTC) en français pour Paris.
 */
function format_date_fr($date_str_utc) {
    if (!$date_str_utc) return 'N/A';
    try {
        $date_utc = new DateTime($date_str_utc, new DateTimeZone('UTC'));
        $date_utc->setTimezone(new DateTimeZone('Europe/Paris'));
        if (class_exists('IntlDateFormatter')) {
            $formatter = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Paris', IntlDateFormatter::GREGORIAN, 'EEEE d MMMM yyyy \'à\' HH\'h\'mm');
            return $formatter->format($date_utc);
        } else {
            return $date_utc->format('d/m/Y H:i');
        }
    } catch (Exception $e) {
        return $date_str_utc;
    }
}

/**
 * Redimensionne, compresse et convertit une image en JPG à QUALITÉ MAXIMALE.
 * Gère l'orientation EXIF et la transparence PNG.
 */
function compressAndResizeImage($source_path, $destination_path, $max_width) {
    $quality = 100; // Qualité JPEG maximale
    $image_info = @getimagesize($source_path);
    if ($image_info === false) {
        error_log("compressAndResizeImage: Failed getimagesize for $source_path");
        return false;
    }
    $mime = $image_info['mime'];

    switch ($mime) {
        case 'image/jpeg': $image = @imagecreatefromjpeg($source_path); break;
        case 'image/png': $image = @imagecreatefrompng($source_path); break;
        default: error_log("compressAndResizeImage: Unsupported MIME $mime"); return false;
    }

    if (!$image) { error_log("compressAndResizeImage: Failed imagecreatefrom for $source_path"); return false; }

    // Gérer l'orientation EXIF
    if ($mime == 'image/jpeg' && function_exists('exif_read_data')) {
        try {
            $exif = @exif_read_data($source_path);
            if ($exif && isset($exif['Orientation'])) {
                $orientation = $exif['Orientation'];
                switch ($orientation) {
                    case 3: $image = imagerotate($image, 180, 0); break;
                    case 6: $image = imagerotate($image, -90, 0); break;
                    case 8: $image = imagerotate($image, 90, 0); break;
                }
            }
        } catch (Exception $e) { /* Ignore EXIF errors */ }
    }

    $width = imagesx($image);
    $height = imagesy($image);
    if ($width <= 0 || $height <= 0) { imagedestroy($image); error_log("compressAndResizeImage: Invalid dims $width x $height"); return false; }

    if ($width <= $max_width) {
        $new_width = $width;
        $new_height = $height;
    } else {
        $ratio = $height / $width;
        $new_width = $max_width;
        $new_height = intval($max_width * $ratio);
    }

    $new_image = imagecreatetruecolor($new_width, $new_height);
    if (!$new_image) { imagedestroy($image); error_log("compressAndResizeImage: Failed imagecreatetruecolor"); return false; }

    // Gérer la transparence PNG en remplissant avec du blanc
    if ($mime == 'image/png') {
        $bg = imagecreatetruecolor($new_width, $new_height);
        $white = imagecolorallocate($bg, 255, 255, 255);
        imagefill($bg, 0, 0, $white);
        // Activer alpha blending pour copier la transparence correctement
        imagealphablending($image, true);
        imagesavealpha($image, true); // S'assurer que l'alpha est sauvegardé
        imagecopyresampled($bg, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image);
        $image = $bg;
    } else {
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image);
        $image = $new_image;
    }

    // Sauvegarder en JPEG avec QUALITÉ 100
    $result = imagejpeg($image, $destination_path, $quality);

    if (!$result) { error_log("compressAndResizeImage: Failed imagejpeg to $destination_path"); }
    imagedestroy($image);
    return $result;
}


/**
 * Vérifier si une image est valide (UTILISÉ PAR compressAndResizeImage implicitement via is_valid_image dans admin_img.php)
 */
function is_valid_image($file_tmp_path) {
    if (!file_exists($file_tmp_path)) return false;

    // Tentative de récupération des informations d'image pour validation de base
    $image_info = @getimagesize($file_tmp_path);
    if ($image_info === false) {
        return false; // Ce n'est probablement pas une image valide
    }

    // Utiliser finfo si disponible pour une vérification MIME plus robuste
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) return false;
        $mime = finfo_file($finfo, $file_tmp_path);
        finfo_close($finfo);
    } else {
        // Fallback sur getimagesize si finfo n'est pas dispo
        $mime = $image_info['mime'] ?? null;
    }

    if (!defined('ALLOWED_IMAGE_TYPES')) {
        // Définir ici comme fallback, mais devrait être dans config.php
        define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/jpg', 'image/png']);
        error_log("Warning: ALLOWED_IMAGE_TYPES was not defined. Using default JPG/PNG.");
    }

    return $mime && in_array($mime, ALLOWED_IMAGE_TYPES);
}

// ===== CORRECTION ICI : Fusion des blocs PHP =====
/* ===== Application Settings Helpers ===== */
if (!function_exists('get_setting')) {
    function get_setting(PDO $pdo, string $key, $default = null) {
        try {
            $stmt = $pdo->prepare("SELECT `value`, `type` FROM app_settings WHERE `key` = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return $default;
            $val = $row['value'];
            switch ($row['type']) {
                case 'int': return (int)$val;
                case 'float': return (float)$val;
                case 'bool': return ($val === '1' || strtolower((string)$val) === 'true');
                case 'json': $decoded = json_decode($val, true); return $decoded === null ? $default : $decoded;
                default: return $val; // text, html, color
            }
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('set_setting')) {
    function set_setting(PDO $pdo, string $key, $value, string $type = 'text', string $group='general', string $label=null, string $help=null) {
        $val = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
        $stmt = $pdo->prepare("
            INSERT INTO app_settings(`key`,`value`,`type`,`group`,`label`,`help`)
            VALUES(?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `type`=VALUES(`type`), `group`=VALUES(`group`), `label`=VALUES(`label`), `help`=VALUES(`help`)
        ");
        return $stmt->execute([$key, $val, $type, $group, $label, $help]);
    }
}


?>
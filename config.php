<?php
// =====================================================
// config.php - MODIFIÉ
// Ne contient plus que des constantes
// =====================================================

// ===== CHEMINS =====
define('BASE_PATH', __DIR__);
define('IMG_PATH', BASE_PATH . '/img');
define('UPLOAD_PATH', IMG_PATH);

// ===== LIMITES =====
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB
define('MAX_IMAGE_WIDTH', 800);
define('MAX_THUMBNAIL_WIDTH', 200);
define('IMAGE_QUALITY', 90);

// ===== FORMATS AUTORISÉS =====
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/jpg', 'image/png']);
define('ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png']);

// ===== PAGINATION =====
define('LOGS_PER_PAGE', 15); // Augmenté

// ===== TIMEZONE =====
// (La fonction format_date_fr gère ça, mais c'est bien de le garder)
date_default_timezone_set('Europe/Paris');

// ===== GESTION ERREURS =====
ini_set('display_errors', 0); // 0 pour production, 1 pour debug
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);

if (ini_get('display_errors') == 1) {
    error_reporting(E_ALL);
}

?>
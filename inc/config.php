<?php
/**
 * SHRA - Stallion Horse Riding Academy
 * Global configuration. Real DB credentials live in inc/config.local.php,
 * which is written by install.php and is never committed.
 */
define('APP_NAME',       'Stallion Horse Riding Academy');
define('APP_SHORT',      'SHRA');
define('APP_VERSION',    '1.0.0');
define('APP_ROOT',       dirname(__DIR__));
define('APP_TIMEZONE',   'Asia/Kolkata');
define('APP_CURRENCY',   '₹');

date_default_timezone_set(APP_TIMEZONE);

$__local = __DIR__ . '/config.local.php';
if (is_file($__local)) {
    require $__local;
    define('APP_INSTALLED', true);
} else {
    define('DB_HOST', 'localhost');
    define('DB_NAME', '');
    define('DB_USER', '');
    define('DB_PASS', '');
    define('DB_PORT', '3306');
    define('APP_INSTALLED', false);
}

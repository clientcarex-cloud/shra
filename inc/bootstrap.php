<?php
/** Single entry include for every page. */
require_once __DIR__ . '/config.php';

if (!APP_INSTALLED) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: ' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/portal/') ? '../install.php' : 'install.php'));
        exit;
    }
} else {
    require_once __DIR__ . '/db.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('SHRASESS');
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/model.php';

<?php
/** Staff authentication + role permissions, and separate customer-portal auth. */

const ROLES = [
    'admin'   => 'Administrator',
    'manager' => 'Manager',
    'staff'   => 'Front Desk / Employee',
    'trainer' => 'Trainer',
];

/** module => roles allowed */
const PERMISSIONS = [
    'dashboard'     => ['admin', 'manager', 'staff', 'trainer'],
    'customers'     => ['admin', 'manager', 'staff', 'trainer'],
    'customers.edit'=> ['admin', 'manager', 'staff'],
    'leads'         => ['admin', 'manager', 'staff'],
    'plans'         => ['admin', 'manager'],
    'subscriptions' => ['admin', 'manager', 'staff'],
    'attendance'    => ['admin', 'manager', 'staff', 'trainer'],
    'guest'         => ['admin', 'manager', 'staff'],
    'billing'       => ['admin', 'manager', 'staff'],
    'payments.verify'=> ['admin', 'manager'],
    'trainers'      => ['admin', 'manager'],
    'reports'       => ['admin', 'manager'],
    'users'         => ['admin'],
    'settings'      => ['admin'],
];

function current_user(): ?array
{
    static $u = null;
    if ($u !== null) return $u ?: null;
    $id = $_SESSION['uid'] ?? null;
    if (!$id) { $u = false; return null; }
    $u = one('SELECT * FROM users WHERE id=? AND status="active"', [$id]) ?: false;
    if (!$u) { unset($_SESSION['uid']); return null; }
    return $u;
}

function is_logged_in(): bool { return current_user() !== null; }

function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        redirect('login.php');
    }
}

function role(): string { return current_user()['role'] ?? ''; }

function can(string $module): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    $allowed = PERMISSIONS[$module] ?? [];
    return in_array($u['role'], $allowed, true);
}

function require_can(string $module): void
{
    require_login();
    if (!can($module)) {
        http_response_code(403);
        include APP_ROOT . '/inc/denied.php';
        exit;
    }
}

function attempt_login(string $login, string $password): bool
{
    $u = one('SELECT * FROM users WHERE (email=? OR phone=? OR username=?) AND status="active" LIMIT 1',
             [$login, $login, $login]);
    if (!$u || !password_verify($password, $u['password_hash'])) return false;
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    update('users', ['last_login' => now()], 'id=?', [$u['id']]);
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** ---------- Customer portal (separate session key) ---------- */
function portal_customer(): ?array
{
    static $c = null;
    if ($c !== null) return $c ?: null;
    $id = $_SESSION['cid'] ?? null;
    if (!$id) { $c = false; return null; }
    $c = one('SELECT * FROM customers WHERE id=? AND status="active"', [$id]) ?: false;
    return $c ?: null;
}

function require_portal(): void
{
    if (!portal_customer()) redirect('login.php');
}

function portal_login(string $phone, string $pin): bool
{
    $phone = preg_replace('/\D+/', '', $phone);
    $c = one('SELECT * FROM customers WHERE REPLACE(REPLACE(phone," ",""),"-","") LIKE ? AND status="active" LIMIT 1',
             ['%' . substr($phone, -10)]);
    if (!$c || !$c['portal_pin'] || !hash_equals($c['portal_pin'], $pin)) return false;
    session_regenerate_id(true);
    $_SESSION['cid'] = (int)$c['id'];
    return true;
}

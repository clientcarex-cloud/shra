<?php
/** Shared page chrome: sidebar, top bar, mobile tab bar, flash messages. */

function nav_items(): array
{
    return [
        ['sec' => 'Overview'],
        ['url' => 'index.php',         'ic' => 'home',    'label' => 'Dashboard',     'perm' => 'dashboard'],
        ['sec' => 'Front desk'],
        ['url' => 'guest_ride.php',    'ic' => 'horse',   'label' => 'Guest Ride',    'perm' => 'guest'],
        ['url' => 'attendance.php',    'ic' => 'check',   'label' => 'Attendance',    'perm' => 'attendance'],
        ['url' => 'invoice_new.php',   'ic' => 'receipt', 'label' => 'New Bill',      'perm' => 'billing'],
        ['sec' => 'Manage'],
        ['url' => 'customers.php',     'ic' => 'users',   'label' => 'Customers',     'perm' => 'customers'],
        ['url' => 'subscriptions.php', 'ic' => 'calendar','label' => 'Subscriptions', 'perm' => 'subscriptions'],
        ['url' => 'invoices.php',      'ic' => 'card',    'label' => 'Billing',       'perm' => 'billing'],
        ['url' => 'leads.php',         'ic' => 'trend',   'label' => 'Leads',         'perm' => 'leads'],
        ['url' => 'trainers.php',      'ic' => 'whistle', 'label' => 'Trainers',      'perm' => 'trainers'],
        ['url' => 'plans.php',         'ic' => 'trophy',  'label' => 'Plans & Fees',  'perm' => 'plans'],
        ['sec' => 'Admin'],
        ['url' => 'reports.php',       'ic' => 'chart',   'label' => 'Reports',       'perm' => 'reports'],
        ['url' => 'qr_poster.php',     'ic' => 'qr',      'label' => 'Self-Billing QR','perm' => 'billing'],
        ['url' => 'users.php',         'ic' => 'user',    'label' => 'Staff Logins',  'perm' => 'users'],
        ['url' => 'settings.php',      'ic' => 'gear',    'label' => 'Settings',      'perm' => 'settings'],
    ];
}

function tab_items(): array
{
    return [
        ['url' => 'index.php',      'ic' => 'home',   'label' => 'Home',     'perm' => 'dashboard'],
        ['url' => 'customers.php',  'ic' => 'users',  'label' => 'Riders',   'perm' => 'customers'],
        ['url' => 'attendance.php', 'ic' => 'check',  'label' => 'Mark',     'perm' => 'attendance'],
        ['url' => 'invoices.php',   'ic' => 'card',   'label' => 'Billing',  'perm' => 'billing'],
        ['url' => 'leads.php',      'ic' => 'trend',  'label' => 'Leads',    'perm' => 'leads'],
    ];
}

/** Path of an uploaded logo, or null when the built-in mark should be used. */
function custom_logo(): ?array
{
    static $found = false, $cache = null;
    if ($found) return $cache;
    $found = true;
    foreach (['svg', 'png', 'webp', 'jpg'] as $ext) {
        $f = APP_ROOT . '/assets/img/logo-custom.' . $ext;
        if (is_file($f)) { $cache = ['file' => $f, 'ext' => $ext, 'rel' => 'assets/img/logo-custom.' . $ext]; break; }
    }
    return $cache;
}

/**
 * The academy mark, ready to drop into any sized container.
 * Uses the uploaded logo when there is one, otherwise the built-in SVG.
 */
function logo_svg(string $class = ''): string
{
    $cls = trim('shra-logo ' . $class);
    $custom = custom_logo();

    if ($custom) {
        if ($custom['ext'] === 'svg') {
            $svg = file_get_contents($custom['file']);
            $svg = preg_replace('/\s(?:width|height)="[^"]*"/', '', $svg, 2);
            return str_replace('<svg ', '<svg class="' . e($cls) . '" ', $svg);
        }
        // base_url() resolves correctly from /portal/ and from printed pages too.
        return '<img class="' . e($cls) . '" src="'
             . e(base_url($custom['rel']) . '?v=' . filemtime($custom['file']))
             . '" alt="' . e(setting('academy_name', APP_NAME)) . '">';
    }

    static $svg = null;
    if ($svg === null) {
        $f = APP_ROOT . '/assets/img/logo-mark.svg';
        $svg = is_file($f) ? file_get_contents($f) : '';
        // Drop the intrinsic px size so the mark always scales to its container.
        $svg = preg_replace('/\s(?:width|height)="\d+"/', '', $svg, 2);
    }
    return str_replace('<svg ', '<svg class="' . e($cls) . '" ', $svg);
}

/** Browser-tab icon: the uploaded logo when there is one, else the bundled mark. */
function favicon_url(string $base = ''): string
{
    $c = custom_logo();
    if ($c) return $base . $c['rel'] . '?v=' . filemtime($c['file']);
    return $base . 'assets/img/favicon.svg';
}

function current_page(): string { return basename($_SERVER['SCRIPT_NAME'] ?? ''); }

function layout_header(string $title, array $opts = []): void
{
    $cur  = current_page();
    $user = current_user();
    $base = $opts['base'] ?? '';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#3b2417">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> &middot; <?= e(setting('academy_short', APP_SHORT)) ?></title>
<link rel="icon" href="<?= e(favicon_url($base)) ?>">
<link rel="stylesheet" href="<?= $base ?>assets/css/shra.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="scrim" id="scrim"></div>
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <?= logo_svg() ?>
    <div>
      <b><?= e(setting('academy_short', 'SHRA')) ?></b>
      <span>Riding Academy</span>
    </div>
  </div>
  <nav class="nav">
    <?php foreach (nav_items() as $it):
        if (isset($it['sec'])) { echo '<div class="nav-sec">' . e($it['sec']) . '</div>'; continue; }
        if (!can($it['perm'])) continue;
        $on = $cur === $it['url'] || ($cur === 'customer_view.php' && $it['url'] === 'customers.php')
              || ($cur === 'invoice_view.php' && $it['url'] === 'invoices.php')
              || ($cur === 'subscription_view.php' && $it['url'] === 'subscriptions.php')
              || ($cur === 'lead_view.php' && $it['url'] === 'leads.php'); ?>
      <a class="<?= $on ? 'on' : '' ?>" href="<?= $base . $it['url'] ?>"><span class="ic"><?= icon($it['ic']) ?></span><?= e($it['label']) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="foot">
    <div><b style="color:var(--cream-100)"><?= e($user['name'] ?? '') ?></b></div>
    <div><?= e(ROLES[$user['role'] ?? ''] ?? '') ?> &middot; <a href="<?= $base ?>logout.php" style="color:var(--red-500)">Sign out</a></div>
  </div>
</aside>

<header class="topbar">
  <button class="burger" id="burger" aria-label="Menu">&#9776;</button>
  <div class="title"><?= e($title) ?></div>
  <a href="<?= $base ?>profile.php" class="avatar" title="<?= e($user['name'] ?? '') ?>"><?= e(initials($user['name'] ?? 'S')) ?></a>
</header>

<div class="app"><main class="main">
<?php
    foreach (flash_pull() as $f) {
        echo '<div class="flash flash-' . e($f['type']) . '"><span>' . $f['msg'] . '</span></div>';
    }
}

function layout_footer(array $opts = []): void
{
    $cur = current_page(); $base = $opts['base'] ?? '';
    ?>
</main></div>
<nav class="tabbar">
  <?php foreach (tab_items() as $t): if (!can($t['perm'])) continue; ?>
    <a class="<?= $cur === $t['url'] ? 'on' : '' ?>" href="<?= $base . $t['url'] ?>">
      <span class="ic"><?= icon($t['ic'], 22) ?></span><?= e($t['label']) ?></a>
  <?php endforeach; ?>
</nav>
<script src="<?= $base ?>assets/js/app.js?v=<?= APP_VERSION ?>"></script>
</body></html>
<?php
}

/** Bare shell for public / print pages (no auth chrome). */
function plain_header(string $title, string $base = ''): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#3b2417">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> &middot; <?= e(setting('academy_short', APP_SHORT)) ?></title>
<link rel="icon" href="<?= e(favicon_url($base)) ?>">
<link rel="stylesheet" href="<?= $base ?>assets/css/shra.css?v=<?= APP_VERSION ?>">
</head>
<body>
<?php }

function plain_footer(string $base = ''): void
{
    ?>
<script src="<?= $base ?>assets/js/app.js?v=<?= APP_VERSION ?>"></script>
</body></html>
<?php }

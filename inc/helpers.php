<?php
/** Presentation + utility helpers used across every page. */

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function money($v, bool $symbol = true): string
{
    $n = number_format((float)$v, 2, '.', '');
    // Indian grouping (1,23,456.78)
    [$int, $dec] = explode('.', $n);
    $neg = str_starts_with($int, '-');
    $int = ltrim($int, '-');
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest  = substr($int, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int   = $rest . ',' . $last3;
    }
    return ($symbol ? APP_CURRENCY : '') . ($neg ? '-' : '') . $int . '.' . $dec;
}

function dmy(?string $d): string
{
    if (!$d || $d === '0000-00-00' || str_starts_with((string)$d, '0000')) return '—';
    $t = strtotime($d);
    return $t ? date('d M Y', $t) : '—';
}

function dmyt(?string $d): string
{
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date('d M Y, g:i A', $t) : '—';
}

function today(): string { return date('Y-m-d'); }
function now(): string   { return date('Y-m-d H:i:s'); }

/** Read a POST/GET value with a default. */
function post(string $k, $d = null) { return $_POST[$k] ?? $d; }
function get(string $k, $d = null)  { return $_GET[$k]  ?? $d; }
function pint(string $k, int $d = 0): int { return (int)($_POST[$k] ?? $d); }
function gint(string $k, int $d = 0): int { return (int)($_GET[$k]  ?? $d); }
function pdec(string $k, float $d = 0): float { return round((float)str_replace(',', '', (string)($_POST[$k] ?? $d)), 2); }
function pstr(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $d)); }
function gstr(string $k, string $d = ''): string { return trim((string)($_GET[$k]  ?? $d)); }

/** ---- CSRF ---- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $t = $_POST['_csrf'] ?? '';
    if (!$t || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(419);
        die('Session expired. Please go back, reload the page and try again.');
    }
}

/** ---- Flash messages ---- */
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}
function flash_pull(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Redirect and stop. */
function redirect(string $url): void { header('Location: ' . u($url)); exit; }
function back(): void { redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'); }

/** ---- Settings (key/value store, cached per request) ---- */
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT `skey`,`svalue` FROM settings') as $r) $cache[$r['skey']] = $r['svalue'];
    }
    return $cache[$key] ?? $default;
}
function setting_set(string $key, $value): void
{
    q('INSERT INTO settings (`skey`,`svalue`) VALUES (?,?) ON DUPLICATE KEY UPDATE `svalue`=VALUES(`svalue`)',
        [$key, (string)$value]);
}

/**
 * Are extensionless URLs switched on? Falls back to "no" whenever we cannot
 * be sure — a broken database must never take the recovery links with it.
 */
function clean_urls(): bool
{
    static $on = null;
    if ($on !== null) return $on;
    if (!APP_INSTALLED) return $on = false;
    try {
        $on = setting('clean_urls', '1') === '1';
    } catch (Throwable $e) {
        $on = false;
    }
    return $on;
}

/** Strip the .php extension from an internal link when clean URLs are on. */
function u(string $path): string
{
    if ($path === '' || !clean_urls()) return $path;
    return preg_replace('/\.php(?=$|[?#])/i', '', $path);
}

/**
 * Output filter that turns href="x.php" into href="x" across a whole page,
 * so every link — including ones built inside PHP expressions — stays correct.
 * Only touches HTML responses.
 */
function clean_url_filter(string $html): string
{
    if (!clean_urls()) return $html;
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $html;
    }
    return preg_replace('/\b(href|action)="([^"]*?)\.php(?=$|[?#"])/i', '$1="$2', $html) ?? $html;
}

/**
 * Ask this very server whether an extensionless URL actually resolves.
 * Returns true/false, or null when the check cannot run.
 */
function probe_clean_urls(): ?bool
{
    $url = rtrim(base_url(), '/') . '/login';      // public page, cheap to fetch
    $code = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 4, 'ignore_errors' => true],
                                      'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]]);
        @file_get_contents($url, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int)$m[1]; break; }
        }
    }
    if (!$code) return null;
    return $code !== 404;
}

/** Absolute base URL of the app (e.g. https://host/shra). */
function base_url(string $path = ''): string
{
    $cfg = setting('site_url');
    if ($cfg) return rtrim($cfg, '/') . '/' . ltrim(u($path), '/');
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    // portal/ pages sit one level deeper
    if (str_ends_with($dir, '/portal')) $dir = substr($dir, 0, -7);
    return ($https ? 'https://' : 'http://') . $host . $dir . '/' . ltrim(u($path), '/');
}

/** 24576 -> "24 KB" */
function size_label(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

function rand_token(int $len = 16): string
{
    return substr(bin2hex(random_bytes($len)), 0, $len);
}

/** Short human-friendly code, e.g. C-2601-0042 */
function next_customer_code(): string
{
    $n = (int) scalar('SELECT COALESCE(MAX(id),0)+1 FROM customers');
    return 'SHRA' . date('y') . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/** Indian financial year label for a date: 2026-04-01 -> 2026-27 */
function fy_label(string $date): string
{
    $y = (int)date('Y', strtotime($date));
    $m = (int)date('n', strtotime($date));
    $start = $m >= 4 ? $y : $y - 1;
    return $start . '-' . substr((string)($start + 1), 2);
}

/** Age in years from DOB, or null. */
function age_from(?string $dob): ?int
{
    if (!$dob) return null;
    $t = strtotime($dob);
    if (!$t) return null;
    return (int) ((time() - $t) / (365.25 * 86400));
}

function status_badge(string $status): string
{
    $map = [
        'active' => 'ok', 'completed' => 'info', 'expired' => 'warn', 'cancelled' => 'muted',
        'paid' => 'ok', 'unpaid' => 'danger', 'partial' => 'warn', 'pending' => 'warn',
        'new' => 'info', 'contacted' => 'info', 'follow_up' => 'warn', 'visit_scheduled' => 'warn',
        'converted' => 'ok', 'lost' => 'muted', 'inactive' => 'muted',
        'present' => 'ok', 'scheduled' => 'info', 'no_show' => 'danger', 'verified' => 'ok',
    ];
    $cls = $map[$status] ?? 'muted';
    return '<span class="badge badge-' . $cls . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function initials(string $name): string
{
    $p = preg_split('/\s+/', trim($name));
    $s = strtoupper(substr($p[0] ?? 'x', 0, 1));
    if (count($p) > 1) $s .= strtoupper(substr(end($p), 0, 1));
    return $s;
}

/** Paging helper: returns [limit, offset, page]. */
function pager(int $perPage = 25): array
{
    $page = max(1, gint('p', 1));
    return [$perPage, ($page - 1) * $perPage, $page];
}

function pager_links(int $total, int $perPage, int $page, array $qs = []): string
{
    $pages = (int) ceil($total / max(1, $perPage));
    if ($pages <= 1) return '';
    $out = '<div class="pager">';
    $mk = function ($p, $label, $active = false) use ($qs) {
        $qs['p'] = $p;
        return '<a class="' . ($active ? 'on' : '') . '" href="?' . http_build_query($qs) . '">' . $label . '</a>';
    };
    if ($page > 1) $out .= $mk($page - 1, '&laquo;');
    $from = max(1, $page - 2); $to = min($pages, $page + 2);
    if ($from > 1) $out .= $mk(1, '1') . ($from > 2 ? '<span>…</span>' : '');
    for ($i = $from; $i <= $to; $i++) $out .= $mk($i, (string)$i, $i === $page);
    if ($to < $pages) $out .= ($to < $pages - 1 ? '<span>…</span>' : '') . $mk($pages, (string)$pages);
    if ($page < $pages) $out .= $mk($page + 1, '&raquo;');
    return $out . '</div>';
}

function log_activity(string $entity, int $entityId, string $action, string $note = ''): void
{
    insert('activity_log', [
        'entity'    => $entity,
        'entity_id' => $entityId,
        'action'    => $action,
        'note'      => $note,
        'user_id'   => current_user()['id'] ?? null,
        'created_at'=> now(),
    ]);
}

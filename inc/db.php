<?php
/** PDO connection singleton + tiny query helpers. */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+05:30'");
    } catch (PDOException $e) {
        http_response_code(500);
        db_connect_failed($e);
    }

    return $pdo;
}

/** Run a prepared statement and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** First row or null. */
function one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** All rows. */
function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Single scalar value. */
function scalar(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Insert an associative array into a table, return new id. */
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

/** Update a table row by id (or custom where). */
function update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = [];
    foreach (array_keys($data) as $c) $sets[] = "`$c`=?";
    $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE ' . $where;
    return q($sql, array_merge(array_values($data), $whereParams))->rowCount();
}

/**
 * Friendly, actionable page when the database cannot be reached.
 * Never prints the password.
 */
function db_connect_failed(PDOException $e): void
{
    $installer = is_file(APP_ROOT . '/install.php');
    $refused   = str_contains($e->getMessage(), '2002') || str_contains($e->getMessage(), 'refused');
    $denied    = str_contains($e->getMessage(), '1045') || str_contains($e->getMessage(), 'Access denied');
    $noDb      = str_contains($e->getMessage(), '1049') || str_contains($e->getMessage(), 'Unknown database');

    $cause = 'The app could not reach the database.';
    if ($refused) $cause = 'Nothing is listening at <b>' . htmlspecialchars(DB_HOST . ':' . DB_PORT)
                         . '</b>. The saved settings point somewhere this server cannot reach.';
    elseif ($denied) $cause = 'The database rejected the user <b>' . htmlspecialchars(DB_USER) . '</b> — wrong username or password.';
    elseif ($noDb)   $cause = 'The database <b>' . htmlspecialchars(DB_NAME) . '</b> does not exist on this server.';

    echo '<!doctype html><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Database connection failed</title>'
       . '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#fbf7f0;color:#241609;'
       . 'margin:0;padding:1.5rem;line-height:1.55}.w{max-width:620px;margin:6vh auto;background:#fff;'
       . 'border:1px solid #e3d6c2;border-radius:14px;padding:1.6rem;box-shadow:0 8px 28px rgba(43,26,15,.10)}'
       . 'h1{font-size:1.2rem;margin:0 0 .6rem;color:#2b1a0f}code{background:#f6efe4;border:1px solid #e3d6c2;'
       . 'border-radius:5px;padding:.15rem .4rem;font-size:.9em}ol{padding-left:1.2rem}li{margin:.45rem 0}'
       . 'a.btn{display:inline-block;background:#b7302a;color:#fff;text-decoration:none;padding:.65rem 1.1rem;'
       . 'border-radius:8px;font-weight:600;margin-top:.6rem}.d{color:#6b5844;font-size:.85rem;margin-top:1.1rem;'
       . 'border-top:1px solid #e3d6c2;padding-top:.7rem}</style>'
       . '<div class="w"><h1>Database connection failed</h1>'
       . '<p>' . $cause . '</p>'
       . '<p><b>To fix it:</b></p><ol>'
       . '<li>Delete <code>inc/config.local.php</code> on the server.</li>'
       . ($installer
            ? '<li>Open <code>install.php</code> and enter this server&rsquo;s database host, name, user and password.</li>'
            : '<li>Re-upload <code>install.php</code>, open it, and enter this server&rsquo;s database details.</li>')
       . '<li>Delete <code>install.php</code> again once setup finishes.</li>'
       . '</ol>'
       . ($installer ? '<a class="btn" href="install.php?force=1">Open the installer</a>' : '')
       . '<div class="d">Trying <code>' . htmlspecialchars(DB_USER . '@' . DB_HOST . ':' . DB_PORT)
       . '</code>, database <code>' . htmlspecialchars(DB_NAME) . '</code>.<br>'
       . htmlspecialchars($e->getMessage()) . '</div></div>';
    exit;
}

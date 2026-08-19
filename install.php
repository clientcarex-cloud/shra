<?php
/**
 * SHRA installer. Run once after uploading to the VPS, then delete this file
 * (the app refuses to run it again while inc/config.local.php exists unless
 * you pass ?force=1).
 */
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/schema.php';

$done = false; $errors = []; $step = 'form';

if (APP_INSTALLED && !isset($_GET['force'])) {
    $step = 'already';
}

if ($step === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $port = trim($_POST['db_port'] ?? '3306');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = (string)($_POST['db_pass'] ?? '');

    $aName  = trim($_POST['admin_name'] ?? '');
    $aEmail = trim($_POST['admin_email'] ?? '');
    $aPhone = trim($_POST['admin_phone'] ?? '');
    $aPass  = (string)($_POST['admin_pass'] ?? '');

    if ($name === '' || $user === '') $errors[] = 'Database name and user are required.';
    if ($aName === '' || $aEmail === '') $errors[] = 'Administrator name and email are required.';
    if (strlen($aPass) < 8) $errors[] = 'Administrator password must be at least 8 characters.';

    if (!$errors) {
        try {
            $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $name) . "`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `" . str_replace('`', '', $name) . "`");

            foreach (schema_statements() as $sql) $pdo->exec($sql);
            seed_data($pdo);

            // Administrator
            $exists = $pdo->prepare('SELECT id FROM users WHERE email=? OR username=?');
            $exists->execute([$aEmail, 'admin']);
            if (!$exists->fetch()) {
                $ins = $pdo->prepare('INSERT INTO users (name,username,email,phone,password_hash,role,status,created_at)
                                      VALUES (?,?,?,?,?,"admin","active",NOW())');
                $ins->execute([$aName, 'admin', $aEmail, $aPhone, password_hash($aPass, PASSWORD_DEFAULT)]);
            }

            $cfg = "<?php\n"
                 . "/* Written by install.php — keep this file private. */\n"
                 . "define('DB_HOST', " . var_export($host, true) . ");\n"
                 . "define('DB_PORT', " . var_export($port, true) . ");\n"
                 . "define('DB_NAME', " . var_export($name, true) . ");\n"
                 . "define('DB_USER', " . var_export($user, true) . ");\n"
                 . "define('DB_PASS', " . var_export($pass, true) . ");\n";
            if (@file_put_contents(__DIR__ . '/inc/config.local.php', $cfg) === false) {
                $errors[] = 'Could not write inc/config.local.php — check folder permissions, '
                          . 'or create the file manually with the contents shown below.';
                $manual = $cfg;
            } else {
                @chmod(__DIR__ . '/inc/config.local.php', 0640);
                $step = 'done';
            }
        } catch (Throwable $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install &middot; Stallion Horse Riding Academy</title>
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/shra.css">
</head><body class="auth">
<div class="auth-card" style="max-width:520px">
  <div class="auth-logo"><?= file_get_contents(__DIR__ . '/assets/img/logo-mark.svg') ?>
    <h1>Stallion Horse Riding Academy</h1><p>Installation</p></div>

<?php if ($step === 'already'): ?>
  <div class="flash flash-info"><span>The app is already installed. Delete <code>install.php</code> from the
    server, or open <a href="install.php?force=1">install.php?force=1</a> to re-run setup.</span></div>
  <a class="btn btn-block" href="login.php">Go to sign in</a>

<?php elseif ($step === 'done'): ?>
  <div class="flash flash-success"><span><b>Setup complete.</b> Tables created, fee plans from your rate card
    loaded, and the administrator account is ready.</span></div>
  <div class="flash flash-warn"><span><b>Now delete <code>install.php</code></b> from the server.</span></div>
  <a class="btn btn-block" href="login.php">Sign in</a>

<?php else: ?>
  <?php foreach ($errors as $er): ?>
    <div class="flash flash-error"><span><?= htmlspecialchars($er) ?></span></div>
  <?php endforeach; ?>
  <?php if (!empty($manual)): ?>
    <pre class="card pad small" style="overflow:auto"><?= htmlspecialchars($manual) ?></pre>
  <?php endif; ?>
  <form method="post">
    <fieldset><legend>MySQL database</legend>
      <div class="grid-2">
        <div class="field"><label>Host</label>
          <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>"></div>
        <div class="field"><label>Port</label>
          <input type="text" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>"></div>
      </div>
      <div class="field"><label>Database name <span class="req">*</span></label>
        <input type="text" name="db_name" required value="<?= htmlspecialchars($_POST['db_name'] ?? 'shra') ?>">
        <div class="help">Created automatically if the user has permission.</div></div>
      <div class="field"><label>Database user <span class="req">*</span></label>
        <input type="text" name="db_user" required value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>"></div>
      <div class="field"><label>Database password</label>
        <input type="password" name="db_pass" value=""></div>
    </fieldset>

    <fieldset><legend>Administrator account</legend>
      <div class="field"><label>Full name <span class="req">*</span></label>
        <input type="text" name="admin_name" required value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>"></div>
      <div class="field"><label>Email <span class="req">*</span></label>
        <input type="email" name="admin_email" required value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>">
        <div class="help">You can sign in with this email, the phone number, or the username <b>admin</b>.</div></div>
      <div class="field"><label>Mobile</label>
        <input type="tel" name="admin_phone" value="<?= htmlspecialchars($_POST['admin_phone'] ?? '') ?>"></div>
      <div class="field"><label>Password <span class="req">*</span></label>
        <input type="password" name="admin_pass" required minlength="8"></div>
    </fieldset>

    <button class="btn btn-block btn-red" type="submit">Install SHRA</button>
  </form>
<?php endif; ?>
</div>
</body></html>

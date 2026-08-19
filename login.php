<?php
require_once __DIR__ . '/inc/bootstrap.php';
if (is_logged_in()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login = pstr('login');
    $pass  = (string) post('password', '');
    // crude throttle: 5 tries / 10 min per session
    $tries = $_SESSION['login_tries'] ?? [];
    $tries = array_filter($tries, fn($t) => $t > time() - 600);
    if (count($tries) >= 5) {
        $error = 'Too many attempts. Please wait 10 minutes and try again.';
    } elseif (attempt_login($login, $pass)) {
        unset($_SESSION['login_tries']);
        $to = $_SESSION['after_login'] ?? 'index.php';
        unset($_SESSION['after_login']);
        redirect($to);
    } else {
        $tries[] = time();
        $_SESSION['login_tries'] = $tries;
        $error = 'Incorrect login or password.';
    }
}
plain_header('Sign in');
?>
<div class="auth">
  <div class="auth-card">
    <div class="auth-logo">
      <?= logo_svg() ?>
      <h1><?= e(setting('academy_name', APP_NAME)) ?></h1>
      <p>Staff Sign In</p>
    </div>
    <?php if ($error): ?><div class="flash flash-error"><span><?= e($error) ?></span></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field">
        <label>Email, mobile or username</label>
        <input type="text" name="login" required autofocus autocomplete="username"
               value="<?= e(post('login', '')) ?>">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" required autocomplete="current-password">
      </div>
      <button class="btn btn-block btn-red" type="submit">Sign in</button>
    </form>
    <hr>
    <p class="center small muted">Are you a rider or parent?<br>
      <a href="portal/login.php"><b>Open the rider portal &rarr;</b></a></p>
  </div>
</div>
<?php plain_footer(); ?>

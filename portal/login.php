<?php
require_once __DIR__ . '/../inc/bootstrap.php';
if (portal_customer()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tries = array_filter($_SESSION['portal_tries'] ?? [], fn($t) => $t > time() - 600);
    if (count($tries) >= 6) {
        $error = 'Too many attempts. Please try again in 10 minutes.';
    } elseif (portal_login(pstr('phone'), pstr('pin'))) {
        unset($_SESSION['portal_tries']);
        redirect('index.php');
    } else {
        $tries[] = time();
        $_SESSION['portal_tries'] = $tries;
        $error = 'We could not match that mobile number and PIN.';
    }
}

// A QR from a rider's profile carries their code; use it only as a friendly hint.
$hint = gstr('c');
plain_header('Rider portal', '../');
?>
<div class="auth">
  <div class="auth-card">
    <div class="auth-logo">
      <?= logo_svg() ?>
      <h1><?= e(setting('academy_name', APP_NAME)) ?></h1>
      <p>Rider Portal</p>
    </div>
    <?php if ($error): ?><div class="flash flash-error"><span><?= e($error) ?></span></div><?php endif; ?>
    <?php if ($hint): ?>
      <div class="flash flash-info"><span>Sign in for rider <b><?= e($hint) ?></b> using the mobile number
        registered with us.</span></div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field"><label>Mobile number</label>
        <input type="tel" name="phone" required autofocus inputmode="numeric" placeholder="10-digit number"></div>
      <div class="field"><label>4-digit PIN</label>
        <input type="password" name="pin" required inputmode="numeric" maxlength="8" placeholder="••••">
        <div class="help">Ask the front desk for your PIN if you do not have it.</div></div>
      <button class="btn btn-block btn-red" type="submit">Sign in</button>
    </form>
    <hr>
    <p class="center small muted">
      <a href="../self.php">Book &amp; pay for a ride &rarr;</a><br>
      <?= e(setting('academy_phone', '')) ?>
    </p>
  </div>
</div>
<?php plain_footer('../'); ?>

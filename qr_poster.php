<?php
/** Printable counter poster carrying the self-billing and rider-portal QR codes. */
require_once __DIR__ . '/inc/bootstrap.php';
require_can('billing');
require_once __DIR__ . '/inc/qrcode.php';

$selfUrl   = base_url('self.php');
$portalUrl = base_url('portal/login.php');
$which     = gstr('show', 'self');
$url       = $which === 'portal' ? $portalUrl : $selfUrl;

layout_header('Self-billing QR');
?>
<div class="no-print">
  <div class="desk-bar page-h"><h1>Counter QR codes</h1></div>
  <div class="toolbar">
    <a class="btn btn-s <?= $which === 'self' ? '' : 'btn-ghost' ?>" href="?show=self">Self billing</a>
    <a class="btn btn-s <?= $which === 'portal' ? '' : 'btn-ghost' ?>" href="?show=portal">Rider portal</a>
    <button class="btn btn-s btn-red" data-print><?= icon('print', 16) ?>  Print poster</button>
    <button class="btn btn-s btn-ghost" data-copy="<?= e($url) ?>">Copy link</button>
    <a class="btn btn-s btn-ghost" href="qr.php?d=<?= urlencode($url) ?>&s=900&f=png" download="shra-qr.png">Download PNG</a>
  </div>
  <?php if ($which === 'self' && !setting('upi_id')): ?>
    <div class="flash flash-warn"><span>No UPI ID is set yet, so riders will see the bill but no UPI QR.
      Add it in <a href="settings.php">Settings</a>.</span></div>
  <?php endif; ?>
</div>

<div class="card" style="max-width:620px;margin:0 auto">
  <div class="card-b center" style="padding:2rem 1.4rem">
    <div style="width:110px;margin:0 auto;color:var(--brown-800)"><?= logo_svg() ?></div>
    <h2 style="margin:.7rem 0 .1rem;font-size:1.25rem"><?= e(setting('academy_name', APP_NAME)) ?></h2>
    <p class="muted" style="letter-spacing:.2em;text-transform:uppercase;font-size:.7rem;margin-bottom:1.2rem">
      <?= $which === 'portal' ? 'Rider Portal' : 'Scan • Book • Pay' ?></p>

    <div class="qr-box" style="border-width:2px"><?= QRCode::svg($url, 300, 'Q') ?></div>

    <h3 style="margin-top:1.1rem;font-size:1.05rem">
      <?= $which === 'portal'
          ? 'Check your sessions and bills'
          : 'Book a ride and pay in seconds' ?></h3>
    <p class="muted small" style="max-width:360px;margin:.4rem auto 0">
      <?= $which === 'portal'
          ? 'Sign in with your mobile number and the 4-digit PIN from the front desk to see remaining sessions, ride history and invoices.'
          : 'Point your phone camera at this code, choose your plan, and pay by UPI. Your receipt is generated instantly.' ?></p>

    <hr style="margin:1.3rem 0">
    <p class="small muted" style="margin:0">
      <?= e(setting('academy_address', '')) ?><br>
      <?= e(setting('academy_phone', '')) ?>
      <?php if (setting('academy_instagram')): ?> &middot; @<?= e(setting('academy_instagram')) ?><?php endif; ?><br>
      <?= e(setting('academy_website', '')) ?></p>
  </div>
</div>
<?php layout_footer(); ?>

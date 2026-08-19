<?php
/** Rider self-service portal: sessions left, ride history, invoices. */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/qrcode.php';
require_portal();

$c   = portal_customer();
$cid = (int) $c['id'];

$subs = all('SELECT s.*, t.name AS trainer FROM subscriptions s
             LEFT JOIN trainers t ON t.id=s.trainer_id
             WHERE s.customer_id=? ORDER BY FIELD(s.status,"active","completed","expired","cancelled"), s.id DESC', [$cid]);
$rides = all('SELECT r.*, t.name AS trainer FROM ride_sessions r
              LEFT JOIN trainers t ON t.id=r.trainer_id
              WHERE r.customer_id=? AND r.status IN ("present","scheduled")
              ORDER BY r.ride_date DESC, r.id DESC LIMIT 25', [$cid]);
$invs = all('SELECT * FROM invoices WHERE customer_id=? AND status<>"cancelled" ORDER BY id DESC LIMIT 25', [$cid]);
$due  = (float) scalar('SELECT COALESCE(SUM(total-paid_amount),0) FROM invoices
                        WHERE customer_id=? AND status IN ("unpaid","partial")', [$cid]);
$totalRides = (int) scalar('SELECT COUNT(*) FROM ride_sessions WHERE customer_id=? AND status="present"', [$cid]);

plain_header('My rides', '../');
?>
<header class="topbar" style="position:sticky">
  <span style="width:34px;color:var(--tan-300)"><?= logo_svg() ?></span>
  <div class="title">Hello, <?= e($c['first_name']) ?></div>
  <a href="logout.php" class="btn btn-s btn-ghost" style="color:var(--cream-100);border-color:rgba(220,196,160,.4)">Sign out</a>
</header>

<div class="app"><main class="main" style="max-width:720px;margin:0 auto">

  <div class="stats" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat blue"><span class="k">Rides</span><span class="v"><?= $totalRides ?></span>
      <span class="s">completed</span></div>
    <div class="stat"><span class="k">Rider code</span>
      <span class="v money"><?= e($c['code']) ?></span>
      <span class="s"><?= e(ucfirst($c['riding_level'])) ?></span></div>
    <div class="stat <?= $due > 0 ? 'red' : 'green' ?>"><span class="k">Balance</span>
      <span class="v money"><?= money($due) ?></span></div>
  </div>

  <h2>My packages</h2>
  <?php if (!$subs): ?>
    <div class="card"><div class="empty">
      <div class="big-icon"><?= icon('horse', 44) ?></div>No package yet.<br>
      <a class="btn mt" href="../self.php">Book a ride</a></div></div>
  <?php else: foreach ($subs as $s): $p = subscription_progress($s); ?>
    <div class="card">
      <div class="card-h"><h3><?= e($s['plan_name']) ?></h3><?= status_badge($s['status']) ?></div>
      <div class="card-b">
        <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
          <div class="ring" style="--p:<?= $p['pct'] ?>"><div>
            <b><?= $p['left'] ?></b><small>left</small></div></div>
          <div style="flex:1;min-width:170px">
            <dl class="kv">
              <dt>Attended</dt><dd><b><?= $p['used'] ?></b> of <?= $p['total'] ?></dd>
              <dt>Valid till</dt><dd><?= dmy($s['end_date']) ?></dd>
              <?php if ($s['trainer']): ?><dt>Trainer</dt><dd><?= e($s['trainer']) ?></dd><?php endif; ?>
              <dt>Class</dt><dd><?= (int)$s['duration_min'] ?> min</dd>
            </dl>
          </div>
        </div>
        <div class="dots">
          <?php for ($i = 0; $i < $p['total']; $i++): ?><i class="<?= $i < $p['used'] ? 'f' : '' ?>"></i><?php endfor; ?>
        </div>
        <?php if ($s['status'] === 'active' && $p['left'] <= 2): ?>
          <div class="flash flash-warn mt"><span>Only <b><?= $p['left'] ?></b> session<?= $p['left'] === 1 ? '' : 's' ?>
            left — renew at the front desk or <a href="../self.php">book online</a>.</span></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <h2>Bills</h2>
  <?php if (!$invs): ?>
    <div class="card"><div class="empty small">No invoices yet.</div></div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($invs as $i): $bal = invoice_balance($i); ?>
        <a class="list-item" href="../pay.php?t=<?= e($i['token']) ?>">
          <div class="g"><b><?= e($i['invoice_no']) ?></b>
            <span><?= dmy($i['issue_date']) ?> &middot; <?= money($i['total']) ?></span></div>
          <div class="right">
            <?= status_badge($i['status']) ?>
            <?php if ($bal > 0): ?>
              <div class="small" style="color:var(--red-600)"><b><?= money($bal) ?> due</b></div><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2>Ride history</h2>
  <?php if (!$rides): ?>
    <div class="card"><div class="empty small">No rides recorded yet.</div></div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($rides as $r): ?>
        <div class="list-item">
          <div class="g"><b><?= dmy($r['ride_date']) ?>
              <?= $r['ride_time'] ? '<span class="small muted">' . date('g:i A', strtotime($r['ride_time'])) . '</span>' : '' ?></b>
            <span style="white-space:normal">
              <?= $r['trainer'] ? 'with ' . e($r['trainer']) : '' ?>
              <?= $r['horse_name'] ? ' &middot; ' . e($r['horse_name']) : '' ?>
              <?= $r['skills'] ? '<br>' . e($r['skills']) : '' ?>
              <?= $r['remarks'] ? '<br><i>' . e($r['remarks']) . '</i>' : '' ?></span></div>
          <span class="pill"><?= (int)$r['duration_min'] ?> min</span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card"><div class="card-b center">
    <a class="btn btn-red btn-block" href="../self.php">Book &amp; pay for a ride</a>
    <p class="small muted mt"><?= e(setting('academy_name', APP_NAME)) ?><br>
      <?= e(setting('academy_address', '')) ?><br><?= e(setting('academy_phone', '')) ?></p>
  </div></div>
</main></div>
<?php plain_footer('../'); ?>

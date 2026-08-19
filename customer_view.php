<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('customers');
require_once __DIR__ . '/inc/qrcode.php';

$id = gint('id');
$c  = find_customer($id);
if (!$c) { flash('Rider not found.', 'error'); redirect('customers.php'); }

/* ---- actions ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = pstr('action');

    if ($act === 'reset_pin' && can('customers.edit')) {
        $pin = (string) random_int(1000, 9999);
        update('customers', ['portal_pin' => $pin], 'id=?', [$id]);
        flash('New portal PIN: <b>' . e($pin) . '</b>');
    }
    if ($act === 'cancel_sub' && can('subscriptions')) {
        $sid = pint('sub_id');
        update('subscriptions', ['status' => 'cancelled'], 'id=? AND customer_id=?', [$sid, $id]);
        flash('Package cancelled.', 'info');
    }
    redirect('customer_view.php?id=' . $id . '#' . gstr('tab', 'overview'));
}

$tab   = gstr('tab', 'overview');
$name  = customer_name($c);
$age   = age_from($c['dob']);

$subs = all('SELECT s.*, t.name AS trainer FROM subscriptions s
             LEFT JOIN trainers t ON t.id=s.trainer_id
             WHERE s.customer_id=? ORDER BY FIELD(s.status,"active","completed","expired","cancelled"), s.id DESC', [$id]);

$rides = all('SELECT r.*, t.name AS trainer FROM ride_sessions r
              LEFT JOIN trainers t ON t.id=r.trainer_id
              WHERE r.customer_id=? ORDER BY r.ride_date DESC, r.id DESC LIMIT 60', [$id]);

$invs = can('billing') ? all('SELECT * FROM invoices WHERE customer_id=? ORDER BY id DESC', [$id]) : [];

$due   = (float) scalar('SELECT COALESCE(SUM(total-paid_amount),0) FROM invoices
                         WHERE customer_id=? AND status IN ("unpaid","partial")', [$id]);
$spent = (float) scalar('SELECT COALESCE(SUM(p.amount),0) FROM payments p
                         JOIN invoices i ON i.id=p.invoice_id
                         WHERE i.customer_id=? AND p.status="verified"', [$id]);
$ridesDone = (int) scalar('SELECT COUNT(*) FROM ride_sessions WHERE customer_id=? AND status="present"', [$id]);

$portalUrl = base_url('portal/login.php?c=' . urlencode($c['code']));

layout_header($name);
?>
<div class="card">
  <div class="card-b">
    <div style="display:flex;gap:.9rem;align-items:flex-start">
      <span class="avatar-lg"><?= e(initials($name)) ?></span>
      <div style="flex:1;min-width:0">
        <h1 style="margin:0 0 .15rem;font-size:1.25rem"><?= e($name) ?></h1>
        <div class="small muted"><?= e($c['code']) ?>
          &middot; <?= e(ucfirst($c['category'])) ?><?= $age !== null ? ' &middot; ' . $age . ' yrs' : '' ?>
          &middot; <?= e(ucfirst($c['riding_level'])) ?></div>
        <div class="mt-s btn-row">
          <a class="btn btn-s" href="tel:<?= e($c['phone']) ?>"><?= icon('user', 16) ?> <?= e($c['phone']) ?></a>
          <?php $wa = preg_replace('/\D+/', '', $c['phone']); if ($wa): ?>
            <a class="btn btn-s btn-ghost" target="_blank" rel="noopener"
               href="https://wa.me/<?= e(strlen($wa) === 10 ? '91' . $wa : $wa) ?>">WhatsApp</a>
          <?php endif; ?>
          <?php if (can('customers.edit')): ?>
            <a class="btn btn-s btn-ghost" href="customer_edit.php?id=<?= $id ?>">Edit</a><?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($c['medical_notes']): ?>
      <div class="flash flash-warn mt"><span><b>Medical note:</b> <?= e($c['medical_notes']) ?></span></div>
    <?php endif; ?>

    <div class="stats mt" style="grid-template-columns:repeat(3,1fr);margin-bottom:0">
      <div class="stat"><span class="k">Rides</span><span class="v"><?= $ridesDone ?></span></div>
      <div class="stat green"><span class="k">Paid</span><span class="v money"><?= money($spent) ?></span></div>
      <div class="stat <?= $due > 0 ? 'red' : '' ?>"><span class="k">Due</span>
        <span class="v money"><?= money($due) ?></span></div>
    </div>
  </div>
  <div class="card-f btn-row">
    <?php if (can('attendance')): ?>
      <a class="btn btn-s" href="attendance.php?customer_id=<?= $id ?>"><?= icon('check', 16) ?> Mark ride</a><?php endif; ?>
    <?php if (can('subscriptions')): ?>
      <a class="btn btn-s" href="subscription_edit.php?customer_id=<?= $id ?>"><?= icon('calendar', 16) ?> New package</a><?php endif; ?>
    <?php if (can('billing')): ?>
      <a class="btn btn-s btn-red" href="invoice_new.php?customer_id=<?= $id ?>"><?= icon('receipt', 16) ?> New bill</a><?php endif; ?>
  </div>
</div>

<div class="tabs">
  <a class="<?= $tab === 'overview' ? 'on' : '' ?>"   href="?id=<?= $id ?>&tab=overview">Overview</a>
  <a class="<?= $tab === 'packages' ? 'on' : '' ?>"   href="?id=<?= $id ?>&tab=packages">Packages (<?= count($subs) ?>)</a>
  <a class="<?= $tab === 'rides' ? 'on' : '' ?>"      href="?id=<?= $id ?>&tab=rides">Ride history</a>
  <?php if (can('billing')): ?>
    <a class="<?= $tab === 'billing' ? 'on' : '' ?>"  href="?id=<?= $id ?>&tab=billing">Billing (<?= count($invs) ?>)</a><?php endif; ?>
</div>

<?php if ($tab === 'overview'): ?>
  <div class="split wide">
    <div class="card">
      <div class="card-h"><h2>Registration details</h2></div>
      <div class="card-b">
        <dl class="kv">
          <dt>Father / spouse</dt><dd><?= e($c['father_spouse'] ?: '—') ?></dd>
          <dt>Guardian</dt><dd><?= e($c['guardian_name'] ?: '—') ?><?= $c['guardian_rel'] ? ' (' . e($c['guardian_rel']) . ')' : '' ?></dd>
          <dt>Date of birth</dt><dd><?= dmy($c['dob']) ?></dd>
          <dt>Place of birth</dt><dd><?= e($c['place_of_birth'] ?: '—') ?></dd>
          <dt>Gender</dt><dd><?= e($c['gender'] ? ucfirst($c['gender']) : '—') ?></dd>
          <dt>Email</dt><dd><?= e($c['email'] ?: '—') ?></dd>
          <dt>Address</dt><dd><?= e($c['address'] ?: '—') ?></dd>
          <dt>City / PIN</dt><dd><?= e(trim(($c['city'] ?: '—') . ' ' . $c['postcode'])) ?></dd>
          <dt>Nationality</dt><dd><?= e($c['nationality'] ?: '—') ?></dd>
          <dt>Source</dt><dd><?= e($c['source'] ?: '—') ?></dd>
          <dt>Registered</dt><dd><?= dmy($c['created_at']) ?></dd>
        </dl>
        <?php if ($c['notes']): ?><hr><p class="small"><?= nl2br(e($c['notes'])) ?></p><?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><h3>Rider portal access</h3></div>
      <div class="card-b center">
        <div class="qr-box"><?= QRCode::svg($portalUrl, 168, 'M') ?>
          <div class="cap">Scan to open the portal</div></div>
        <p class="small mt">The rider signs in with their mobile number and this PIN to see
          remaining sessions, ride history and bills.</p>
        <p style="font-size:1.6rem;font-weight:700;letter-spacing:.3em;margin:.3rem 0">
          <?= e($c['portal_pin'] ?: '— — — —') ?></p>
        <?php if (can('customers.edit')): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="reset_pin">
            <button class="btn btn-s btn-ghost" data-confirm="Generate a new portal PIN for this rider?">Reset PIN</button>
          </form>
        <?php endif; ?>
        <button class="btn btn-s btn-ghost" data-copy="<?= e($portalUrl) ?>">Copy link</button>
      </div>
    </div>
  </div>

<?php elseif ($tab === 'packages'): ?>
  <?php if (!$subs): ?>
    <div class="card"><div class="empty"><div class="big-icon"><?= icon('calendar', 44) ?></div>No packages yet.
      <?php if (can('subscriptions')): ?><br><a href="subscription_edit.php?customer_id=<?= $id ?>">Start a subscription</a><?php endif; ?></div></div>
  <?php else: foreach ($subs as $s): $p = subscription_progress($s); ?>
    <div class="card">
      <div class="card-h"><h3><?= e($s['plan_name']) ?></h3><?= status_badge($s['status']) ?></div>
      <div class="card-b">
        <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
          <div class="ring" style="--p:<?= $p['pct'] ?>"><div>
            <b><?= $p['used'] ?>/<?= $p['total'] ?></b><small>sessions</small></div></div>
          <div style="flex:1;min-width:180px">
            <dl class="kv">
              <dt>Remaining</dt><dd><b><?= $p['left'] ?></b> sessions</dd>
              <dt>Valid</dt><dd><?= dmy($s['start_date']) ?> – <?= dmy($s['end_date']) ?></dd>
              <?php if ($p['days_left'] !== null && $s['status'] === 'active'): ?>
                <dt>Days left</dt><dd><?= max(0, $p['days_left']) ?></dd><?php endif; ?>
              <dt>Trainer</dt><dd><?= e($s['trainer'] ?: 'Not assigned') ?></dd>
              <dt>Price</dt><dd><?= money($s['price']) ?></dd>
            </dl>
          </div>
        </div>
        <div class="dots">
          <?php for ($i = 0; $i < $p['total']; $i++): ?><i class="<?= $i < $p['used'] ? 'f' : '' ?>"></i><?php endfor; ?>
        </div>
      </div>
      <div class="card-f btn-row">
        <a class="btn btn-s btn-ghost" href="subscription_view.php?id=<?= (int)$s['id'] ?>">Open</a>
        <?php if ($s['status'] === 'active' && can('attendance')): ?>
          <a class="btn btn-s" href="attendance.php?customer_id=<?= $id ?>&subscription_id=<?= (int)$s['id'] ?>">Mark session</a>
        <?php endif; ?>
        <?php if ($s['status'] === 'active' && can('subscriptions')): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel_sub">
            <input type="hidden" name="sub_id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="tab" value="packages">
            <button class="btn btn-s btn-ghost" data-confirm="Cancel this package?">Cancel</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>

<?php elseif ($tab === 'rides'): ?>
  <div class="card">
    <div class="card-h"><h2>Ride history</h2></div>
    <?php if (!$rides): ?><div class="empty">No rides recorded yet.</div><?php else: ?>
    <div class="tbl-wrap"><table class="stack">
      <thead><tr><th>Date</th><th>Type</th><th>Trainer</th><th>Horse</th><th>Notes</th><th></th></tr></thead>
      <tbody><?php foreach ($rides as $r): ?>
        <tr>
          <td data-l="Date"><b><?= dmy($r['ride_date']) ?></b>
            <?php if ($r['ride_time']): ?><br><span class="small muted"><?= date('g:i A', strtotime($r['ride_time'])) ?></span><?php endif; ?></td>
          <td data-l="Type"><span class="pill"><?= $r['ride_type'] === 'guest' ? 'Guest' : 'Package' ?></span></td>
          <td data-l="Trainer"><?= e($r['trainer'] ?: '—') ?></td>
          <td data-l="Horse"><?= e($r['horse_name'] ?: '—') ?></td>
          <td data-l="Notes" class="full"><?= e($r['remarks'] ?: ($r['skills'] ?: '—')) ?></td>
          <td data-l="Status"><?= status_badge($r['status']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <div class="card">
    <div class="card-h"><h2>Invoices</h2>
      <a class="btn btn-s btn-red" href="invoice_new.php?customer_id=<?= $id ?>">New bill</a></div>
    <?php if (!$invs): ?><div class="empty">No invoices yet.</div><?php else: ?>
    <div class="tbl-wrap"><table class="stack">
      <thead><tr><th>Invoice</th><th>Date</th><th class="num">Total</th><th class="num">Balance</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($invs as $i): ?>
        <tr>
          <td data-l="Invoice"><a href="invoice_view.php?id=<?= (int)$i['id'] ?>"><b><?= e($i['invoice_no']) ?></b></a></td>
          <td data-l="Date"><?= dmy($i['issue_date']) ?></td>
          <td data-l="Total" class="num"><?= money($i['total']) ?></td>
          <td data-l="Balance" class="num"><?= money(invoice_balance($i)) ?></td>
          <td data-l="Status"><?= status_badge($i['status']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php layout_footer(); ?>

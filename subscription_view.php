<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('subscriptions');

$id = gint('id');
$s  = one('SELECT s.*, c.first_name, c.last_name, c.code, c.phone, c.id AS cid, t.name AS trainer
           FROM subscriptions s JOIN customers c ON c.id=s.customer_id
           LEFT JOIN trainers t ON t.id=s.trainer_id WHERE s.id=?', [$id]);
if (!$s) { flash('Package not found.', 'error'); redirect('subscriptions.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = pstr('action');
    if ($act === 'extend') {
        $days = max(1, pint('days'));
        $newEnd = date('Y-m-d', strtotime(($s['end_date'] ?: today()) . ' +' . $days . ' days'));
        update('subscriptions', ['end_date' => $newEnd, 'status' => 'active'], 'id=?', [$id]);
        log_activity('subscription', $id, 'extended', $days . ' days');
        flash('Validity extended to ' . dmy($newEnd) . '.');
    }
    if ($act === 'add_sessions') {
        $n = max(1, pint('sessions'));
        update('subscriptions', ['total_sessions' => (int)$s['total_sessions'] + $n], 'id=?', [$id]);
        refresh_subscription($id);
        log_activity('subscription', $id, 'sessions added', (string)$n);
        flash($n . ' session(s) added.');
    }
    if ($act === 'set_trainer') {
        update('subscriptions', ['trainer_id' => pint('trainer_id') ?: null], 'id=?', [$id]);
        flash('Trainer updated.');
    }
    if ($act === 'status') {
        update('subscriptions', ['status' => pstr('status')], 'id=?', [$id]);
        flash('Status updated.', 'info');
    }
    redirect('subscription_view.php?id=' . $id);
}

$p = subscription_progress($s);
$rides = all('SELECT r.*, t.name AS trainer FROM ride_sessions r LEFT JOIN trainers t ON t.id=r.trainer_id
              WHERE r.subscription_id=? ORDER BY r.ride_date DESC, r.id DESC', [$id]);
$inv = one('SELECT * FROM invoices WHERE subscription_id=? ORDER BY id DESC LIMIT 1', [$id]);
$name = trim($s['first_name'] . ' ' . $s['last_name']);

layout_header($s['plan_name']);
?>
<div class="card">
  <div class="card-h"><h1 style="font-size:1.15rem;margin:0;flex:1"><?= e($s['plan_name']) ?></h1>
    <?= status_badge($s['status']) ?></div>
  <div class="card-b">
    <a class="list-item" style="padding-left:0" href="customer_view.php?id=<?= (int)$s['cid'] ?>">
      <span class="avatar-lg"><?= e(initials($name)) ?></span>
      <div class="g"><b><?= e($name) ?></b><span><?= e($s['code']) ?> &middot; <?= e($s['phone']) ?></span></div>
    </a>
    <div style="display:flex;gap:1.2rem;align-items:center;flex-wrap:wrap;margin-top:1rem">
      <div class="ring" style="--p:<?= $p['pct'] ?>"><div>
        <b><?= $p['pct'] ?>%</b><small>complete</small></div></div>
      <div style="flex:1;min-width:200px">
        <dl class="kv">
          <dt>Sessions attended</dt><dd><b><?= $p['used'] ?></b> of <?= $p['total'] ?></dd>
          <dt>Remaining</dt><dd><b><?= $p['left'] ?></b></dd>
          <dt>Class duration</dt><dd><?= (int)$s['duration_min'] ?> min</dd>
          <dt>Valid</dt><dd><?= dmy($s['start_date']) ?> – <?= dmy($s['end_date']) ?></dd>
          <?php if ($p['days_left'] !== null): ?>
            <dt>Days left</dt><dd><?= $p['days_left'] >= 0 ? $p['days_left'] : 'Expired' ?></dd><?php endif; ?>
          <dt>Trainer</dt><dd><?= e($s['trainer'] ?: 'Not assigned') ?></dd>
          <dt>Price</dt><dd><?= money($s['price']) ?></dd>
          <?php if ($inv): ?><dt>Invoice</dt>
            <dd><a href="invoice_view.php?id=<?= (int)$inv['id'] ?>"><?= e($inv['invoice_no']) ?></a>
              <?= status_badge($inv['status']) ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>
    <div class="dots">
      <?php for ($i = 0; $i < $p['total']; $i++): ?><i class="<?= $i < $p['used'] ? 'f' : '' ?>"></i><?php endfor; ?>
    </div>
    <?php if ($s['notes']): ?><hr><p class="small"><?= nl2br(e($s['notes'])) ?></p><?php endif; ?>
  </div>
  <div class="card-f btn-row">
    <?php if ($s['status'] === 'active' && can('attendance')): ?>
      <a class="btn btn-red" href="attendance.php?customer_id=<?= (int)$s['cid'] ?>&subscription_id=<?= $id ?>"><?= icon('check', 16) ?> Mark session</a>
    <?php endif; ?>
    <?php if (can('billing')): ?>
      <a class="btn btn-ghost" href="invoice_new.php?customer_id=<?= (int)$s['cid'] ?>&subscription_id=<?= $id ?>">Raise bill</a>
    <?php endif; ?>
  </div>
</div>

<div class="split wide">
  <div class="card">
    <div class="card-h"><h2>Session log</h2><span class="pill"><?= count($rides) ?> recorded</span></div>
    <?php if (!$rides): ?>
      <div class="empty">No sessions attended yet.</div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="stack">
        <thead><tr><th>#</th><th>Date</th><th>Trainer</th><th>Horse</th><th>Notes</th><th></th></tr></thead>
        <tbody><?php $n = count($rides); foreach ($rides as $r): ?>
          <tr>
            <td data-l="#"><b><?= $n-- ?></b></td>
            <td data-l="Date"><?= dmy($r['ride_date']) ?>
              <?php if ($r['ride_time']): ?><br><span class="small muted"><?= date('g:i A', strtotime($r['ride_time'])) ?></span><?php endif; ?></td>
            <td data-l="Trainer"><?= e($r['trainer'] ?: '—') ?></td>
            <td data-l="Horse"><?= e($r['horse_name'] ?: '—') ?></td>
            <td data-l="Notes" class="full"><?= e($r['remarks'] ?: ($r['skills'] ?: '—')) ?></td>
            <td data-l="Status"><?= status_badge($r['status']) ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-h"><h3>Adjust package</h3></div>
      <div class="card-b">
        <form method="post" class="mb"><?= csrf_field() ?>
          <input type="hidden" name="action" value="extend">
          <label class="lbl">Extend validity</label>
          <div style="display:flex;gap:.5rem">
            <input type="number" name="days" value="15" min="1" style="flex:1">
            <button class="btn btn-ghost">Add days</button>
          </div>
        </form>
        <form method="post" class="mb"><?= csrf_field() ?>
          <input type="hidden" name="action" value="add_sessions">
          <label class="lbl">Add sessions</label>
          <div style="display:flex;gap:.5rem">
            <input type="number" name="sessions" value="1" min="1" style="flex:1">
            <button class="btn btn-ghost">Add</button>
          </div>
        </form>
        <form method="post" class="mb"><?= csrf_field() ?>
          <input type="hidden" name="action" value="set_trainer">
          <label class="lbl">Trainer</label>
          <div style="display:flex;gap:.5rem">
            <select name="trainer_id" style="flex:1">
              <option value="">— none —</option>
              <?php foreach (trainer_options() as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= (int)$s['trainer_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                  <?= e($t['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-ghost">Set</button>
          </div>
        </form>
        <form method="post"><?= csrf_field() ?>
          <input type="hidden" name="action" value="status">
          <label class="lbl">Status</label>
          <div style="display:flex;gap:.5rem">
            <select name="status" style="flex:1">
              <?php foreach (['active', 'completed', 'expired', 'cancelled'] as $st): ?>
                <option value="<?= $st ?>" <?= $s['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php layout_footer(); ?>

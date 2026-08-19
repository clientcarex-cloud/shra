<?php
/** Daily attendance: check a rider in and burn one session from their package. */
require_once __DIR__ . '/inc/bootstrap.php';
require_can('attendance');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = pstr('action');

    if ($act === 'mark') {
        $cid = pint('customer_id');
        $cust = find_customer($cid);
        if (!$cust) { flash('Choose a rider.', 'error'); redirect('attendance.php'); }

        $subId  = pint('subscription_id') ?: null;
        $status = pstr('status', 'present');

        if ($subId) {
            $sub = one('SELECT * FROM subscriptions WHERE id=? AND customer_id=?', [$subId, $cid]);
            if (!$sub) { flash('That package does not belong to this rider.', 'error'); redirect('attendance.php'); }
            if ($status === 'present' && (int)$sub['used_sessions'] >= (int)$sub['total_sessions']) {
                flash('That package has no sessions left. Add sessions or start a new package.', 'error');
                redirect('subscription_view.php?id=' . $subId);
            }
        }

        $rid = mark_attendance([
            'customer_id'     => $cid,
            'subscription_id' => $subId,
            'trainer_id'      => pint('trainer_id') ?: null,
            'ride_type'       => $subId ? 'subscription' : 'guest',
            'horse_name'      => pstr('horse_name'),
            'ride_date'       => pstr('ride_date', today()),
            'ride_time'       => pstr('ride_time') ?: date('H:i'),
            'duration_min'    => pint('duration_min') ?: 30,
            'status'          => $status,
            'skills'          => pstr('skills'),
            'remarks'         => pstr('remarks'),
        ]);
        log_activity('ride', $rid, 'marked ' . $status);
        flash('Attendance recorded for <b>' . e(customer_name($cust)) . '</b>.');
        redirect('attendance.php?date=' . urlencode(pstr('ride_date', today())));
    }

    if ($act === 'delete') {
        $r = one('SELECT * FROM ride_sessions WHERE id=?', [pint('id')]);
        if ($r) {
            q('DELETE FROM ride_sessions WHERE id=?', [$r['id']]);
            if ($r['subscription_id']) refresh_subscription((int)$r['subscription_id']);
            flash('Entry removed and the session credited back.', 'info');
        }
        redirect('attendance.php?date=' . urlencode(pstr('ride_date', today())));
    }
}

$date = gstr('date', today());
$preCustomer = gint('customer_id') ? find_customer(gint('customer_id')) : null;
$preSub = gint('subscription_id')
    ? one('SELECT * FROM subscriptions WHERE id=?', [gint('subscription_id')])
    : ($preCustomer ? active_subscription((int)$preCustomer['id']) : null);

$subs = $preCustomer
    ? all('SELECT * FROM subscriptions WHERE customer_id=? AND status="active" ORDER BY end_date', [$preCustomer['id']])
    : [];

$rows = all('SELECT r.*, c.first_name, c.last_name, c.code, c.id AS cid, t.name AS trainer,
                    s.plan_name, s.used_sessions, s.total_sessions
             FROM ride_sessions r
             JOIN customers c ON c.id=r.customer_id
             LEFT JOIN trainers t ON t.id=r.trainer_id
             LEFT JOIN subscriptions s ON s.id=r.subscription_id
             WHERE r.ride_date=? ORDER BY r.id DESC', [$date]);

$counts = ['present' => 0, 'guest' => 0, 'no_show' => 0];
foreach ($rows as $r) {
    if ($r['status'] === 'present') { $counts['present']++; if ($r['ride_type'] === 'guest') $counts['guest']++; }
    if ($r['status'] === 'no_show') $counts['no_show']++;
}

layout_header('Attendance');
?>
<div class="desk-bar page-h"><h1>Attendance</h1></div>

<div class="card">
  <div class="card-h"><h2>Check in a rider</h2></div>
  <form method="post"><div class="card-b">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mark">

    <?php if ($preCustomer): ?>
      <input type="hidden" name="customer_id" value="<?= (int)$preCustomer['id'] ?>">
      <div class="list-item" style="padding-left:0">
        <span class="avatar-lg"><?= e(initials(customer_name($preCustomer))) ?></span>
        <div class="g"><b><?= e(customer_name($preCustomer)) ?></b>
          <span><?= e($preCustomer['code']) ?> &middot; <?= e($preCustomer['phone']) ?></span></div>
        <a class="btn btn-s btn-ghost" href="attendance.php?date=<?= e($date) ?>">Change</a>
      </div>
    <?php else: ?>
      <div class="field"><label>Rider <span class="req">*</span></label>
        <input type="text" data-cust-search="att-cust" data-submit-on-pick
               placeholder="Type a name or mobile number…" autocomplete="off">
        <input type="hidden" name="customer_id" id="att-cust" required>
        <div class="help">Picking a rider loads their active package automatically.</div></div>
    <?php endif; ?>

    <?php if ($preCustomer): ?>
      <div class="field"><label>Session counts against</label>
        <select name="subscription_id">
          <?php if (!$subs): ?><option value="">Guest ride (no package)</option><?php endif; ?>
          <?php foreach ($subs as $s): $pp = subscription_progress($s); ?>
            <option value="<?= (int)$s['id'] ?>" <?= ($preSub && (int)$preSub['id'] === (int)$s['id']) ? 'selected' : '' ?>>
              <?= e($s['plan_name']) ?> — <?= $pp['left'] ?> of <?= $pp['total'] ?> left
            </option>
          <?php endforeach; ?>
          <?php if ($subs): ?><option value="">Guest ride (do not use a package)</option><?php endif; ?>
        </select>
        <?php if (!$subs): ?>
          <div class="help">No active package. <a href="subscription_edit.php?customer_id=<?= (int)$preCustomer['id'] ?>">Start one</a>
            or <a href="guest_ride.php?customer_id=<?= (int)$preCustomer['id'] ?>">bill a guest ride</a>.</div>
        <?php endif; ?>
      </div>

      <div class="grid-2">
        <div class="field"><label>Date</label>
          <input type="date" name="ride_date" value="<?= e($date) ?>"></div>
        <div class="field"><label>Time</label>
          <input type="time" name="ride_time" value="<?= date('H:i') ?>"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Trainer</label>
          <select name="trainer_id">
            <option value="">— Not assigned —</option>
            <?php foreach (trainer_options() as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>Horse</label>
          <input type="text" name="horse_name" placeholder="Optional"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Duration (min)</label>
          <input type="number" name="duration_min" min="5" step="5"
                 value="<?= (int)($preSub['duration_min'] ?? 30) ?>"></div>
        <div class="field"><label>Result</label>
          <select name="status">
            <option value="present">Present — use a session</option>
            <option value="no_show">No show</option>
            <option value="cancelled">Cancelled</option>
            <option value="scheduled">Scheduled (future)</option>
          </select></div>
      </div>
      <div class="field"><label>Skills practised</label>
        <input type="text" name="skills" placeholder="Mounting, walk, trot, posture…"></div>
      <div class="field"><label>Trainer remarks</label>
        <textarea name="remarks" rows="2"></textarea></div>
    <?php endif; ?>
  </div>
  <?php if ($preCustomer): ?>
    <div class="card-f"><button class="btn btn-red btn-block" type="submit">Record attendance</button></div>
  <?php endif; ?>
  </form>
</div>

<div class="toolbar"><form method="get" data-autosubmit>
  <label class="lbl" style="align-self:center;margin:0">Showing</label>
  <input type="date" name="date" value="<?= e($date) ?>" style="max-width:190px">
  <button class="btn btn-ghost btn-s">Go</button>
</form></div>

<div class="card">
  <div class="card-h">
    <h2><?= dmy($date) ?></h2>
    <span class="pill"><?= $counts['present'] ?> present</span>
    <?php if ($counts['guest']): ?><span class="pill"><?= $counts['guest'] ?> guest</span><?php endif; ?>
    <?php if ($counts['no_show']): ?><span class="pill"><?= $counts['no_show'] ?> no-show</span><?php endif; ?>
  </div>
  <?php if (!$rows): ?>
    <div class="empty"><div class="big-icon"><?= icon('check', 44) ?></div>No attendance recorded for this day.</div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="stack">
    <thead><tr><th>Rider</th><th>Time</th><th>Package</th><th>Trainer</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr>
        <td data-l="Rider"><a href="customer_view.php?id=<?= (int)$r['cid'] ?>">
            <b><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></b></a><br>
          <span class="small muted"><?= e($r['code']) ?></span></td>
        <td data-l="Time"><?= $r['ride_time'] ? date('g:i A', strtotime($r['ride_time'])) : '—' ?><br>
          <span class="small muted"><?= (int)$r['duration_min'] ?> min</span></td>
        <td data-l="Package"><?= $r['plan_name']
              ? e($r['plan_name']) . '<br><span class="small muted">' . (int)$r['used_sessions'] . '/' . (int)$r['total_sessions'] . ' used</span>'
              : '<span class="pill">Guest ride</span>' ?></td>
        <td data-l="Trainer"><?= e($r['trainer'] ?: '—') ?></td>
        <td data-l="Status"><?= status_badge($r['status']) ?></td>
        <td data-l="" class="num">
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="ride_date" value="<?= e($date) ?>">
            <button class="btn btn-s btn-ghost" data-confirm="Remove this entry and credit the session back?">Undo</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_footer(); ?>

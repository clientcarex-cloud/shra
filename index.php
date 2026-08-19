<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('dashboard');

expire_stale_subscriptions();

$today = today();
$monthStart = date('Y-m-01');

$stat = [
  'rides_today' => (int) scalar('SELECT COUNT(*) FROM ride_sessions WHERE ride_date=? AND status="present"', [$today]),
  'active_subs' => (int) scalar('SELECT COUNT(*) FROM subscriptions WHERE status="active"'),
  'customers'   => (int) scalar('SELECT COUNT(*) FROM customers WHERE status="active"'),
];
// Money is only fetched — let alone shown — for roles that may see it.
if (can('billing')) {
    $stat['month_revenue'] = (float) scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status="verified" AND paid_at>=?', [$monthStart . ' 00:00:00']);
    $stat['today_revenue'] = (float) scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status="verified" AND DATE(paid_at)=?', [$today]);
    $stat['outstanding']   = (float) scalar('SELECT COALESCE(SUM(total-paid_amount),0) FROM invoices WHERE status IN ("unpaid","partial")');
    $stat['pending_pay']   = (int) scalar('SELECT COUNT(*) FROM payments WHERE status="pending"');
}
if (can('leads')) {
    $stat['open_leads'] = (int) scalar('SELECT COUNT(*) FROM leads WHERE status IN ("new","contacted","follow_up","visit_scheduled")');
}
// A trainer sees their own workload instead of the academy's books.
$myTrainerId = role() === 'trainer'
    ? (int) scalar('SELECT id FROM trainers WHERE user_id=? LIMIT 1', [current_user()['id']]) : 0;
if ($myTrainerId) {
    $stat['my_today'] = (int) scalar('SELECT COUNT(*) FROM ride_sessions WHERE trainer_id=? AND ride_date=? AND status="present"', [$myTrainerId, $today]);
    $stat['my_month'] = (int) scalar('SELECT COUNT(*) FROM ride_sessions WHERE trainer_id=? AND status="present" AND ride_date>=?', [$myTrainerId, $monthStart]);
}

$followups = can('leads') ? all(
  'SELECT l.*, u.name AS owner FROM leads l LEFT JOIN users u ON u.id=l.assigned_to
   WHERE l.status IN ("new","contacted","follow_up","visit_scheduled")
     AND (l.next_followup IS NULL OR l.next_followup<=?)
   ORDER BY l.next_followup IS NULL, l.next_followup ASC, l.id DESC LIMIT 6', [$today]) : [];

$expiring = all(
  'SELECT s.*, c.first_name, c.last_name, c.code, c.phone
   FROM subscriptions s JOIN customers c ON c.id=s.customer_id
   WHERE s.status="active" AND (
        (s.total_sessions - s.used_sessions) <= 2
     OR (s.end_date IS NOT NULL AND s.end_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)))
   ORDER BY s.end_date ASC LIMIT 6');

$recentRides = all(
  'SELECT r.*, c.first_name, c.last_name, c.code, t.name AS trainer
   FROM ride_sessions r
   JOIN customers c ON c.id=r.customer_id
   LEFT JOIN trainers t ON t.id=r.trainer_id
   WHERE r.ride_date=? ORDER BY r.id DESC LIMIT 8', [$today]);

$dues = can('billing') ? all(
  'SELECT i.*, c.first_name, c.last_name, c.phone FROM invoices i JOIN customers c ON c.id=i.customer_id
   WHERE i.status IN ("unpaid","partial") ORDER BY i.issue_date ASC LIMIT 6') : [];

layout_header('Dashboard');
?>
<div class="desk-bar page-h"><h1>Dashboard</h1>
  <span class="muted small"><?= dmy($today) ?></span></div>

<?php if (!empty($stat['pending_pay']) && can('payments.verify')): ?>
  <div class="flash flash-warn"><span>
    <b><?= $stat['pending_pay'] ?></b> self-service payment<?= $stat['pending_pay'] > 1 ? 's' : '' ?>
    awaiting verification. <a href="payments.php?status=pending">Review now &rarr;</a></span></div>
<?php endif; ?>

<div class="stats">
  <a class="stat blue" href="attendance.php">
    <span class="k">Rides today</span><span class="v"><?= $stat['rides_today'] ?></span>
    <span class="s"><?= $stat['active_subs'] ?> active packages</span></a>

  <?php if ($myTrainerId): ?>
    <div class="stat green"><span class="k">My sessions today</span><span class="v"><?= $stat['my_today'] ?></span>
      <span class="s"><?= $stat['my_month'] ?> this month</span></div>
  <?php elseif (can('billing')): ?>
    <a class="stat green" href="<?= can('reports') ? 'reports.php' : 'payments.php' ?>">
      <span class="k">Collected today</span><span class="v"><?= money($stat['today_revenue']) ?></span>
      <span class="s"><?= money($stat['month_revenue']) ?> this month</span></a>
  <?php endif; ?>

  <?php if (can('billing')): ?>
    <a class="stat red" href="invoices.php?status=unpaid">
      <span class="k">Outstanding</span><span class="v"><?= money($stat['outstanding']) ?></span>
      <span class="s">across unpaid bills</span></a>
  <?php endif; ?>

  <?php if (can('leads')): ?>
    <a class="stat amber" href="leads.php">
      <span class="k">Open leads</span><span class="v"><?= $stat['open_leads'] ?></span>
      <span class="s"><?= $stat['customers'] ?> active riders</span></a>
  <?php else: ?>
    <a class="stat amber" href="customers.php">
      <span class="k">Active riders</span><span class="v"><?= $stat['customers'] ?></span></a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-h"><h2>Quick actions</h2></div>
  <div class="card-b">
    <div class="btn-row">
      <?php if (can('guest')): ?><a class="btn btn-red" href="guest_ride.php"><?= icon('horse') ?> Guest ride</a><?php endif; ?>
      <?php if (can('attendance')): ?><a class="btn" href="attendance.php"><?= icon('check') ?> Mark attendance</a><?php endif; ?>
      <?php if (can('billing')): ?><a class="btn" href="invoice_new.php"><?= icon('receipt') ?> New bill</a><?php endif; ?>
      <?php if (can('customers.edit')): ?><a class="btn btn-ghost" href="customer_edit.php"><?= icon('plus') ?> Add rider</a><?php endif; ?>
      <?php if (can('leads')): ?><a class="btn btn-ghost" href="lead_edit.php"><?= icon('trend') ?> Add lead</a><?php endif; ?>
    </div>
  </div>
</div>

<div class="split wide">
  <div>
    <div class="card">
      <div class="card-h"><h2>Today's rides</h2><a class="btn btn-s btn-ghost" href="attendance.php">View all</a></div>
      <?php if (!$recentRides): ?>
        <div class="empty"><div class="big-icon"><?= icon('horse', 44) ?></div>No rides marked yet today.</div>
      <?php else: foreach ($recentRides as $r): ?>
        <div class="list-item">
          <span class="avatar-lg"><?= e(initials($r['first_name'] . ' ' . $r['last_name'])) ?></span>
          <div class="g">
            <b><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></b>
            <span><?= e($r['ride_time'] ? date('g:i A', strtotime($r['ride_time'])) : '') ?>
              &middot; <?= (int)$r['duration_min'] ?> min
              <?= $r['trainer'] ? '&middot; ' . e($r['trainer']) : '' ?>
              <?= $r['horse_name'] ? '&middot; ' . e($r['horse_name']) : '' ?></span>
          </div>
          <span class="pill"><?= $r['ride_type'] === 'guest' ? 'Guest' : 'Package' ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <?php if ($dues): ?>
    <div class="card">
      <div class="card-h"><h2>Pending payments</h2><a class="btn btn-s btn-ghost" href="invoices.php?status=unpaid">All</a></div>
      <div class="tbl-wrap"><table class="stack">
        <thead><tr><th>Invoice</th><th>Rider</th><th class="num">Balance</th><th></th></tr></thead>
        <tbody><?php foreach ($dues as $d): ?>
          <tr>
            <td data-l="Invoice"><a href="invoice_view.php?id=<?= (int)$d['id'] ?>"><?= e($d['invoice_no']) ?></a><br>
              <span class="small muted"><?= dmy($d['issue_date']) ?></span></td>
            <td data-l="Rider"><?= e(trim($d['first_name'] . ' ' . $d['last_name'])) ?></td>
            <td data-l="Balance" class="num"><b><?= money(invoice_balance($d)) ?></b></td>
            <td data-l="" class="num"><?= status_badge($d['status']) ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-h"><h3>Needs attention</h3></div>
      <?php if (!$expiring): ?>
        <div class="empty small">All packages are comfortably in progress.</div>
      <?php else: foreach ($expiring as $s): $p = subscription_progress($s); ?>
        <a class="list-item" href="subscription_view.php?id=<?= (int)$s['id'] ?>">
          <div class="g">
            <b><?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?></b>
            <span><?= e($s['plan_name']) ?></span>
            <div class="prog <?= $p['left'] <= 2 ? 'warn' : '' ?>" style="margin-top:.35rem">
              <i style="width:<?= $p['pct'] ?>%"></i></div>
            <span class="small"><?= $p['used'] ?>/<?= $p['total'] ?> sessions
              <?php if ($p['days_left'] !== null): ?>
                &middot; <?= $p['days_left'] >= 0 ? $p['days_left'] . ' days left' : 'expired' ?>
              <?php endif; ?></span>
          </div>
        </a>
      <?php endforeach; endif; ?>
    </div>

    <?php if (can('leads')): ?>
    <div class="card">
      <div class="card-h"><h3>Follow-ups due</h3><a class="btn btn-s btn-ghost" href="leads.php">All</a></div>
      <?php if (!$followups): ?>
        <div class="empty small">No follow-ups pending. </div>
      <?php else: foreach ($followups as $l): ?>
        <a class="list-item" href="lead_view.php?id=<?= (int)$l['id'] ?>">
          <div class="g"><b><?= e($l['name']) ?></b>
            <span><?= e($l['phone']) ?> &middot; <?= $l['next_followup'] ? dmy($l['next_followup']) : 'not scheduled' ?></span></div>
          <?= status_badge($l['status']) ?>
        </a>
      <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php layout_footer(); ?>

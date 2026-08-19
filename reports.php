<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('reports');

$tab  = gstr('tab', 'collections');
$from = gstr('from', date('Y-m-01'));
$to   = gstr('to', today());
$trainerFilter = gint('trainer');

/* ---------------- data ---------------- */
$collections = all(
  'SELECT DATE(p.paid_at) d, COUNT(*) n, SUM(p.amount) amt
   FROM payments p WHERE p.status="verified" AND DATE(p.paid_at) BETWEEN ? AND ?
   GROUP BY DATE(p.paid_at) ORDER BY d DESC', [$from, $to]);

$byMode = all(
  'SELECT p.mode, COUNT(*) n, SUM(p.amount) amt FROM payments p
   WHERE p.status="verified" AND DATE(p.paid_at) BETWEEN ? AND ?
   GROUP BY p.mode ORDER BY amt DESC', [$from, $to]);

$byPlan = all(
  'SELECT COALESCE(pl.name, ii.description) AS plan, SUM(ii.qty) qty, SUM(ii.amount) amt
   FROM invoice_items ii
   JOIN invoices i ON i.id=ii.invoice_id
   LEFT JOIN plans pl ON pl.id=ii.plan_id
   WHERE i.status<>"cancelled" AND i.issue_date BETWEEN ? AND ?
   GROUP BY plan ORDER BY amt DESC', [$from, $to]);

$trainerRows = all(
  'SELECT t.id, t.name, t.session_rate, COUNT(r.id) sessions,
          COUNT(DISTINCT r.customer_id) riders
   FROM trainers t
   LEFT JOIN ride_sessions r ON r.trainer_id=t.id AND r.status="present" AND r.ride_date BETWEEN ? AND ?
   GROUP BY t.id, t.name, t.session_rate ORDER BY sessions DESC', [$from, $to]);

$attendance = all(
  'SELECT r.ride_date d, SUM(r.status="present") present, SUM(r.status="no_show") no_show,
          SUM(r.ride_type="guest" AND r.status="present") guest
   FROM ride_sessions r WHERE r.ride_date BETWEEN ? AND ?
   GROUP BY r.ride_date ORDER BY d DESC', [$from, $to]);

$leadFunnel = all(
  'SELECT status, COUNT(*) n FROM leads WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status', [$from, $to]);
$leadBySource = all(
  'SELECT COALESCE(NULLIF(source,""),"Unknown") src, COUNT(*) n,
          SUM(status="converted") conv
   FROM leads WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY src ORDER BY n DESC', [$from, $to]);

$totals = [
  'collected' => (float) scalar('SELECT COALESCE(SUM(amount),0) FROM payments
                                 WHERE status="verified" AND DATE(paid_at) BETWEEN ? AND ?', [$from, $to]),
  'invoiced'  => (float) scalar('SELECT COALESCE(SUM(total),0) FROM invoices
                                 WHERE status<>"cancelled" AND issue_date BETWEEN ? AND ?', [$from, $to]),
  'rides'     => (int) scalar('SELECT COUNT(*) FROM ride_sessions WHERE status="present" AND ride_date BETWEEN ? AND ?', [$from, $to]),
  'new_cust'  => (int) scalar('SELECT COUNT(*) FROM customers WHERE DATE(created_at) BETWEEN ? AND ?', [$from, $to]),
];

/* ---------------- CSV export ---------------- */
if (gstr('export') === 'csv') {
    $map = [
        'collections' => ['Date,Payments,Amount', array_map(fn($r) => [$r['d'], $r['n'], $r['amt']], $collections)],
        'plans'       => ['Plan,Quantity,Amount',  array_map(fn($r) => [$r['plan'], $r['qty'], $r['amt']], $byPlan)],
        'trainers'    => ['Trainer,Sessions,Riders,Rate,Payout',
                          array_map(fn($r) => [$r['name'], $r['sessions'], $r['riders'], $r['session_rate'],
                                               $r['sessions'] * (float)$r['session_rate']], $trainerRows)],
        'attendance'  => ['Date,Present,No show,Guest',
                          array_map(fn($r) => [$r['d'], $r['present'], $r['no_show'], $r['guest']], $attendance)],
        'leads'       => ['Source,Leads,Converted', array_map(fn($r) => [$r['src'], $r['n'], $r['conv']], $leadBySource)],
    ];
    [$head, $data] = $map[$tab] ?? $map['collections'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shra-' . $tab . '-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, explode(',', $head));
    foreach ($data as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

$qs = fn(array $extra = []) => http_build_query(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to], $extra));
layout_header('Reports');
?>
<div class="desk-bar page-h"><h1>Reports</h1></div>

<div class="toolbar"><form method="get" data-autosubmit>
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <input type="date" name="from" value="<?= e($from) ?>" style="max-width:170px">
  <input type="date" name="to" value="<?= e($to) ?>" style="max-width:170px">
  <button class="btn btn-ghost btn-s">Apply</button>
  <a class="btn btn-s" href="?<?= $qs(['export' => 'csv']) ?>"><?= icon('chart', 16) ?> CSV</a>
</form></div>

<div class="stats">
  <div class="stat green"><span class="k">Collected</span>
    <span class="v money"><?= money($totals['collected']) ?></span></div>
  <div class="stat"><span class="k">Invoiced</span>
    <span class="v money"><?= money($totals['invoiced']) ?></span></div>
  <div class="stat blue"><span class="k">Rides</span><span class="v"><?= $totals['rides'] ?></span></div>
  <div class="stat amber"><span class="k">New riders</span><span class="v"><?= $totals['new_cust'] ?></span></div>
</div>

<div class="tabs">
  <?php foreach (['collections' => 'Collections', 'plans' => 'Plan sales', 'trainers' => 'Trainers',
                  'attendance' => 'Attendance', 'leads' => 'Leads'] as $k => $lb): ?>
    <a class="<?= $tab === $k ? 'on' : '' ?>" href="?<?= http_build_query(['tab' => $k, 'from' => $from, 'to' => $to]) ?>"><?= $lb ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'collections'): ?>
  <div class="split wide">
    <div class="card">
      <div class="card-h"><h2>Day by day</h2></div>
      <?php if (!$collections): ?><div class="empty">No payments in this range.</div><?php else: ?>
      <div class="tbl-wrap"><table class="stack">
        <thead><tr><th>Date</th><th class="num">Payments</th><th class="num">Amount</th></tr></thead>
        <tbody><?php foreach ($collections as $r): ?>
          <tr><td data-l="Date"><?= dmy($r['d']) ?></td>
            <td data-l="Payments" class="num"><?= (int)$r['n'] ?></td>
            <td data-l="Amount" class="num"><b><?= money($r['amt']) ?></b></td></tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
    <div class="card">
      <div class="card-h"><h3>By payment mode</h3></div>
      <?php if (!$byMode): ?><div class="empty small">—</div><?php else: ?>
        <?php $max = max(array_map(fn($r) => (float)$r['amt'], $byMode)); ?>
        <div class="card-b"><?php foreach ($byMode as $r): ?>
          <div class="prog-l"><span><?= e(strtoupper($r['mode'])) ?> (<?= (int)$r['n'] ?>)</span>
            <span><?= money($r['amt']) ?></span></div>
          <div class="prog mb"><i style="width:<?= $max > 0 ? round((float)$r['amt'] * 100 / $max) : 0 ?>%"></i></div>
        <?php endforeach; ?></div>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($tab === 'plans'): ?>
  <div class="card">
    <div class="card-h"><h2>Plan sales</h2></div>
    <?php if (!$byPlan): ?><div class="empty">Nothing billed in this range.</div><?php else: ?>
    <div class="tbl-wrap"><table class="stack">
      <thead><tr><th>Plan</th><th class="num">Sold</th><th class="num">Revenue</th></tr></thead>
      <tbody><?php foreach ($byPlan as $r): ?>
        <tr><td data-l="Plan"><?= e($r['plan']) ?></td>
          <td data-l="Sold" class="num"><?= rtrim(rtrim(number_format((float)$r['qty'], 2), '0'), '.') ?></td>
          <td data-l="Revenue" class="num"><b><?= money($r['amt']) ?></b></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </div>

<?php elseif ($tab === 'trainers'): ?>
  <div class="card">
    <div class="card-h"><h2>Trainer activity</h2></div>
    <?php if (!$trainerRows): ?><div class="empty">No trainers yet.</div><?php else: ?>
    <div class="tbl-wrap"><table class="stack">
      <thead><tr><th>Trainer</th><th class="num">Sessions</th><th class="num">Riders</th>
        <th class="num">Rate</th><th class="num">Payout</th></tr></thead>
      <tbody><?php foreach ($trainerRows as $r): ?>
        <tr>
          <td data-l="Trainer"><b><?= e($r['name']) ?></b></td>
          <td data-l="Sessions" class="num"><?= (int)$r['sessions'] ?></td>
          <td data-l="Riders" class="num"><?= (int)$r['riders'] ?></td>
          <td data-l="Rate" class="num"><?= money($r['session_rate']) ?></td>
          <td data-l="Payout" class="num"><b><?= money((int)$r['sessions'] * (float)$r['session_rate']) ?></b></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <div class="card-f small muted">Payout = sessions marked present in this range &times; the trainer's rate per session.</div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'attendance'): ?>
  <div class="card">
    <div class="card-h"><h2>Attendance</h2></div>
    <?php if (!$attendance): ?><div class="empty">No rides in this range.</div><?php else: ?>
    <div class="tbl-wrap"><table class="stack">
      <thead><tr><th>Date</th><th class="num">Present</th><th class="num">Guest rides</th><th class="num">No shows</th></tr></thead>
      <tbody><?php foreach ($attendance as $r): ?>
        <tr><td data-l="Date"><?= dmy($r['d']) ?></td>
          <td data-l="Present" class="num"><b><?= (int)$r['present'] ?></b></td>
          <td data-l="Guest rides" class="num"><?= (int)$r['guest'] ?></td>
          <td data-l="No shows" class="num"><?= (int)$r['no_show'] ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </div>

<?php else: ?>
  <?php $funnel = []; foreach ($leadFunnel as $r) $funnel[$r['status']] = (int)$r['n'];
        $totalLeads = array_sum($funnel); $converted = $funnel['converted'] ?? 0; ?>
  <div class="split wide">
    <div class="card">
      <div class="card-h"><h2>Lead sources</h2></div>
      <?php if (!$leadBySource): ?><div class="empty">No leads captured in this range.</div><?php else: ?>
      <div class="tbl-wrap"><table class="stack">
        <thead><tr><th>Source</th><th class="num">Leads</th><th class="num">Converted</th><th class="num">Rate</th></tr></thead>
        <tbody><?php foreach ($leadBySource as $r): ?>
          <tr><td data-l="Source"><?= e($r['src']) ?></td>
            <td data-l="Leads" class="num"><?= (int)$r['n'] ?></td>
            <td data-l="Converted" class="num"><?= (int)$r['conv'] ?></td>
            <td data-l="Rate" class="num"><?= (int)$r['n'] ? round((int)$r['conv'] * 100 / (int)$r['n']) : 0 ?>%</td></tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
    <div class="card">
      <div class="card-h"><h3>Funnel</h3></div>
      <div class="card-b">
        <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'follow_up' => 'Follow up',
                        'visit_scheduled' => 'Visit booked', 'converted' => 'Converted', 'lost' => 'Lost'] as $k => $lb):
              $n = $funnel[$k] ?? 0; ?>
          <div class="prog-l"><span><?= $lb ?></span><span><?= $n ?></span></div>
          <div class="prog mb <?= $k === 'converted' ? 'done' : '' ?>">
            <i style="width:<?= $totalLeads ? round($n * 100 / $totalLeads) : 0 ?>%"></i></div>
        <?php endforeach; ?>
        <hr>
        <p class="center"><b style="font-size:1.6rem"><?= $totalLeads ? round($converted * 100 / $totalLeads) : 0 ?>%</b>
          <br><span class="small muted">conversion rate</span></p>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php layout_footer(); ?>

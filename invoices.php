<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('billing');

$status = gstr('status');
$search = gstr('q');
$from   = gstr('from');
$to     = gstr('to');
[$limit, $offset, $page] = pager(20);

$where = ['1=1']; $args = [];
if ($status !== '') { $where[] = 'i.status=?'; $args[] = $status; }
if ($from !== '')   { $where[] = 'i.issue_date>=?'; $args[] = $from; }
if ($to !== '')     { $where[] = 'i.issue_date<=?'; $args[] = $to; }
if ($search !== '') {
    $where[] = '(i.invoice_no LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ?)';
    $l = "%$search%"; array_push($args, $l, $l, $l, $l);
}
$w = implode(' AND ', $where);

$total   = (int) scalar("SELECT COUNT(*) FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE $w", $args);
$sumRow  = one("SELECT COALESCE(SUM(i.total),0) t, COALESCE(SUM(i.paid_amount),0) p
                FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE $w AND i.status<>'cancelled'", $args);
$rows    = all("SELECT i.*, c.first_name, c.last_name, c.phone, c.code
                FROM invoices i JOIN customers c ON c.id=i.customer_id
                WHERE $w ORDER BY i.id DESC LIMIT $limit OFFSET $offset", $args);
$pending = (int) scalar('SELECT COUNT(*) FROM payments WHERE status="pending"');

layout_header('Billing');
?>
<div class="desk-bar page-h"><h1>Billing</h1>
  <a class="btn btn-red" href="invoice_new.php"><?= icon('plus', 16) ?>  New invoice</a></div>

<?php if ($pending && can('payments.verify')): ?>
  <div class="flash flash-warn"><span><b><?= $pending ?></b> self-paid receipt<?= $pending > 1 ? 's' : '' ?>
    waiting for verification. <a href="payments.php?status=pending">Verify &rarr;</a></span></div>
<?php endif; ?>

<div class="stats" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat"><span class="k">Invoiced</span><span class="v money"><?= money($sumRow['t']) ?></span></div>
  <div class="stat green"><span class="k">Received</span><span class="v money"><?= money($sumRow['p']) ?></span></div>
  <div class="stat red"><span class="k">Balance</span>
    <span class="v money"><?= money((float)$sumRow['t'] - (float)$sumRow['p']) ?></span></div>
</div>

<div class="toolbar"><form method="get" data-autosubmit>
  <input class="grow" type="search" name="q" placeholder="Invoice no, rider, mobile…" value="<?= e($search) ?>">
  <select name="status">
    <option value="">All statuses</option>
    <?php foreach (['unpaid' => 'Unpaid', 'partial' => 'Partly paid', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $k => $lb): ?>
      <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lb ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="from" value="<?= e($from) ?>" style="max-width:165px">
  <input type="date" name="to" value="<?= e($to) ?>" style="max-width:165px">
  <button class="btn btn-ghost btn-s">Filter</button>
</form></div>

<div class="card">
  <div class="card-h"><h2><?= $total ?> invoice<?= $total === 1 ? '' : 's' ?></h2>
    <a class="btn btn-s btn-ghost" href="reports.php?tab=collections">Reports</a></div>
  <?php if (!$rows): ?>
    <div class="empty"><div class="big-icon"><?= icon('receipt', 44) ?></div>No invoices match.</div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="stack">
    <thead><tr><th>Invoice</th><th>Rider</th><th>Date</th><th class="num">Total</th>
      <th class="num">Balance</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($rows as $i): ?>
      <tr>
        <td data-l="Invoice"><a href="invoice_view.php?id=<?= (int)$i['id'] ?>"><b><?= e($i['invoice_no']) ?></b></a>
          <?php if ($i['source'] === 'self'): ?><br><span class="pill">Self-billed</span><?php endif; ?></td>
        <td data-l="Rider"><a href="customer_view.php?id=<?= (int)$i['customer_id'] ?>">
            <?= e(trim($i['first_name'] . ' ' . $i['last_name'])) ?></a><br>
          <span class="small muted"><?= e($i['phone']) ?></span></td>
        <td data-l="Date"><?= dmy($i['issue_date']) ?></td>
        <td data-l="Total" class="num"><?= money($i['total']) ?></td>
        <td data-l="Balance" class="num"><?= invoice_balance($i) > 0
              ? '<b style="color:var(--red-600)">' . money(invoice_balance($i)) . '</b>' : '<span class="muted">—</span>' ?></td>
        <td data-l="Status"><?= status_badge($i['status']) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?= pager_links($total, $limit, $page, ['q' => $search, 'status' => $status, 'from' => $from, 'to' => $to]) ?>
<a class="fab" href="invoice_new.php" aria-label="New invoice"><?= icon('plus', 16) ?> </a>
<?php layout_footer(); ?>

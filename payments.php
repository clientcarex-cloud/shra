<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('billing');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_can('payments.verify');
    $pid = pint('id');
    $p   = one('SELECT * FROM payments WHERE id=?', [$pid]);
    if ($p) {
        $act = pstr('action');
        if ($act === 'verify') {
            update('payments', ['status' => 'verified', 'verified_by' => current_user()['id']], 'id=?', [$pid]);
            flash('Payment verified.');
        } elseif ($act === 'reject') {
            update('payments', ['status' => 'rejected', 'verified_by' => current_user()['id']], 'id=?', [$pid]);
            flash('Payment rejected.', 'info');
        } elseif ($act === 'delete') {
            q('DELETE FROM payments WHERE id=?', [$pid]);
            flash('Payment deleted.', 'info');
        }
        invoice_recalc((int)$p['invoice_id']);
    }
    redirect('payments.php?status=' . urlencode(gstr('status')));
}

$status = gstr('status');
$from   = gstr('from', date('Y-m-01'));
$to     = gstr('to', today());
[$limit, $offset, $page] = pager(30);

$where = ['DATE(p.paid_at) BETWEEN ? AND ?']; $args = [$from, $to];
if ($status !== '') { $where[] = 'p.status=?'; $args[] = $status; }
$w = implode(' AND ', $where);

$total = (int) scalar("SELECT COUNT(*) FROM payments p WHERE $w", $args);
$sum   = (float) scalar("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE $w AND p.status='verified'", $args);
$rows  = all("SELECT p.*, i.invoice_no, i.customer_id, c.first_name, c.last_name, u.name AS staff
              FROM payments p
              JOIN invoices i ON i.id=p.invoice_id
              JOIN customers c ON c.id=i.customer_id
              LEFT JOIN users u ON u.id=p.received_by
              WHERE $w ORDER BY p.id DESC LIMIT $limit OFFSET $offset", $args);

layout_header('Payments');
?>
<div class="desk-bar page-h"><h1>Payments</h1></div>

<div class="toolbar"><form method="get" data-autosubmit>
  <select name="status">
    <option value="">All</option>
    <?php foreach (['pending' => 'Awaiting verification', 'verified' => 'Verified', 'rejected' => 'Rejected'] as $k => $lb): ?>
      <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lb ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="from" value="<?= e($from) ?>" style="max-width:165px">
  <input type="date" name="to" value="<?= e($to) ?>" style="max-width:165px">
  <button class="btn btn-ghost btn-s">Filter</button>
</form></div>

<div class="card">
  <div class="card-h"><h2><?= $total ?> payment<?= $total === 1 ? '' : 's' ?></h2>
    <span class="pill">Verified total <?= money($sum) ?></span></div>
  <?php if (!$rows): ?>
    <div class="empty"><div class="big-icon"><?= icon('card', 44) ?></div>No payments in this range.</div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="stack">
    <thead><tr><th>When</th><th>Rider</th><th>Invoice</th><th class="num">Amount</th>
      <th>Mode</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $p): ?>
      <tr>
        <td data-l="When"><?= dmyt($p['paid_at']) ?><br>
          <span class="small muted"><?= $p['source'] === 'self' ? 'Paid by rider' : e($p['staff'] ?: 'Staff') ?></span></td>
        <td data-l="Rider"><a href="customer_view.php?id=<?= (int)$p['customer_id'] ?>">
            <?= e(trim($p['first_name'] . ' ' . $p['last_name'])) ?></a></td>
        <td data-l="Invoice"><a href="invoice_view.php?id=<?= (int)$p['invoice_id'] ?>"><?= e($p['invoice_no']) ?></a></td>
        <td data-l="Amount" class="num"><b><?= money($p['amount']) ?></b></td>
        <td data-l="Mode"><span class="pill"><?= e(strtoupper($p['mode'])) ?></span>
          <?php if ($p['reference']): ?><br><span class="small muted"><?= e($p['reference']) ?></span><?php endif; ?></td>
        <td data-l="Status"><?= status_badge($p['status']) ?></td>
        <td data-l="" class="num nowrap">
          <?php if ($p['status'] === 'pending' && can('payments.verify')): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="status" value="<?= e($status) ?>">
              <button class="btn btn-s btn-green" name="action" value="verify">Verify</button>
              <button class="btn btn-s btn-ghost" name="action" value="reject"
                      data-confirm="Reject this payment claim?">Reject</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?= pager_links($total, $limit, $page, ['status' => $status, 'from' => $from, 'to' => $to]) ?>
<?php layout_footer(); ?>

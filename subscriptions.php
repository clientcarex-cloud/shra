<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('subscriptions');
expire_stale_subscriptions();

$status = gstr('status', 'active');
$search = gstr('q');
[$limit, $offset, $page] = pager(20);

$where = ['1=1']; $args = [];
if ($status !== '') { $where[] = 's.status=?'; $args[] = $status; }
if ($search !== '') {
    $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR c.code LIKE ?)';
    $l = "%$search%"; array_push($args, $l, $l, $l, $l);
}
$w = implode(' AND ', $where);

$total = (int) scalar("SELECT COUNT(*) FROM subscriptions s JOIN customers c ON c.id=s.customer_id WHERE $w", $args);
$rows  = all("SELECT s.*, c.first_name, c.last_name, c.code, c.phone, t.name AS trainer
              FROM subscriptions s
              JOIN customers c ON c.id=s.customer_id
              LEFT JOIN trainers t ON t.id=s.trainer_id
              WHERE $w ORDER BY s.id DESC LIMIT $limit OFFSET $offset", $args);

layout_header('Subscriptions');
?>
<div class="desk-bar page-h"><h1>Subscriptions</h1>
  <a class="btn" href="subscription_edit.php"><?= icon('plus', 16) ?>  New package</a></div>

<div class="toolbar"><form method="get" data-autosubmit>
  <input class="grow" type="search" name="q" placeholder="Search rider…" value="<?= e($search) ?>">
  <select name="status">
    <?php foreach (['active' => 'Active', 'completed' => 'Completed', 'expired' => 'Expired',
                    'cancelled' => 'Cancelled', '' => 'All'] as $k => $lb): ?>
      <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lb ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-ghost btn-s">Filter</button>
</form></div>

<div class="card">
  <div class="card-h"><h2><?= $total ?> package<?= $total === 1 ? '' : 's' ?></h2></div>
  <?php if (!$rows): ?>
    <div class="empty"><div class="big-icon"><?= icon('calendar', 44) ?></div>Nothing here yet.</div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="stack">
    <thead><tr><th>Rider</th><th>Package</th><th>Progress</th><th>Valid till</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($rows as $s): $p = subscription_progress($s); ?>
      <tr>
        <td data-l="Rider"><a href="customer_view.php?id=<?= (int)$s['customer_id'] ?>">
            <b><?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?></b></a><br>
          <span class="small muted"><?= e($s['phone']) ?></span></td>
        <td data-l="Package"><a href="subscription_view.php?id=<?= (int)$s['id'] ?>"><?= e($s['plan_name']) ?></a><br>
          <span class="small muted"><?= e($s['trainer'] ?: 'No trainer') ?></span></td>
        <td data-l="Progress" class="full">
          <div class="prog-l"><span><?= $p['used'] ?>/<?= $p['total'] ?> sessions</span><span><?= $p['pct'] ?>%</span></div>
          <div class="prog <?= $p['pct'] >= 100 ? 'done' : ($p['left'] <= 2 ? 'warn' : '') ?>"><i style="width:<?= $p['pct'] ?>%"></i></div>
        </td>
        <td data-l="Valid till"><?= dmy($s['end_date']) ?>
          <?php if ($s['status'] === 'active' && $p['days_left'] !== null && $p['days_left'] <= 7): ?>
            <br><span class="small" style="color:var(--red-600)"><?= max(0, $p['days_left']) ?> days left</span><?php endif; ?></td>
        <td data-l="Status"><?= status_badge($s['status']) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?= pager_links($total, $limit, $page, ['q' => $search, 'status' => $status]) ?>
<a class="fab" href="subscription_edit.php" aria-label="New package"><?= icon('plus', 16) ?> </a>
<?php layout_footer(); ?>

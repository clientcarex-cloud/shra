<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('customers');

$search   = gstr('q');
$category = gstr('cat');
$status   = gstr('status', 'active');
[$limit, $offset, $page] = pager(20);

$where = ['1=1']; $args = [];
if ($search !== '') {
    $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR c.code LIKE ? OR c.email LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like, $like);
}
if ($category !== '') { $where[] = 'c.category=?'; $args[] = $category; }
if ($status !== '')   { $where[] = 'c.status=?';   $args[] = $status; }
$w = implode(' AND ', $where);

$total = (int) scalar("SELECT COUNT(*) FROM customers c WHERE $w", $args);
$rows  = all("SELECT c.*,
        (SELECT COUNT(*) FROM subscriptions s WHERE s.customer_id=c.id AND s.status='active') AS active_subs,
        (SELECT COALESCE(SUM(i.total-i.paid_amount),0) FROM invoices i
           WHERE i.customer_id=c.id AND i.status IN ('unpaid','partial')) AS due
      FROM customers c WHERE $w ORDER BY c.id DESC LIMIT $limit OFFSET $offset", $args);

layout_header('Customers');
?>
<div class="desk-bar page-h"><h1>Customers</h1>
  <?php if (can('customers.edit')): ?><a class="btn" href="customer_edit.php"><?= icon('plus', 16) ?>  Add rider</a><?php endif; ?></div>

<div class="toolbar">
  <form method="get" data-autosubmit>
    <input class="grow" type="search" name="q" placeholder="Search name, mobile or code…" value="<?= e($search) ?>">
    <select name="cat">
      <option value="">All ages</option>
      <option value="child" <?= $category === 'child' ? 'selected' : '' ?>>Children</option>
      <option value="adult" <?= $category === 'adult' ? 'selected' : '' ?>>Adults</option>
    </select>
    <select name="status">
      <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      <option value=""         <?= $status === ''         ? 'selected' : '' ?>>All</option>
    </select>
    <button class="btn btn-ghost btn-s" type="submit">Filter</button>
  </form>
</div>

<div class="card">
  <div class="card-h"><h2><?= $total ?> rider<?= $total === 1 ? '' : 's' ?></h2></div>
  <?php if (!$rows): ?>
    <div class="empty"><div class="big-icon"><?= icon('users', 44) ?></div>
      No riders match. <?php if (can('customers.edit')): ?><br><a href="customer_edit.php">Add the first one</a><?php endif; ?></div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="stack">
    <thead><tr><th>Rider</th><th>Contact</th><th>Level</th><th>Package</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): $nm = customer_name($c); ?>
      <tr>
        <td data-l="Rider">
          <a href="customer_view.php?id=<?= (int)$c['id'] ?>"><b><?= e($nm) ?></b></a><br>
          <span class="small muted"><?= e($c['code']) ?> &middot; <?= e(ucfirst($c['category'])) ?></span>
        </td>
        <td data-l="Contact"><a href="tel:<?= e($c['phone']) ?>"><?= e($c['phone']) ?></a>
          <?php if ($c['city']): ?><br><span class="small muted"><?= e($c['city']) ?></span><?php endif; ?></td>
        <td data-l="Level"><span class="pill"><?= e(ucfirst($c['riding_level'])) ?></span></td>
        <td data-l="Package"><?= $c['active_subs'] ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-muted">None</span>' ?></td>
        <td data-l="Balance" class="num"><?= (float)$c['due'] > 0
              ? '<b style="color:var(--red-600)">' . money($c['due']) . '</b>' : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?= pager_links($total, $limit, $page, ['q' => $search, 'cat' => $category, 'status' => $status]) ?>
<?php if (can('customers.edit')): ?><a class="fab" href="customer_edit.php" aria-label="Add rider"><?= icon('plus', 16) ?> </a><?php endif; ?>
<?php layout_footer(); ?>

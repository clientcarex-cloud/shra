<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('leads');

$status = gstr('status');
$search = gstr('q');
$owner  = gint('owner');
$due    = gstr('due');
[$limit, $offset, $page] = pager(20);

$where = ['1=1']; $args = [];
if ($status !== '') { $where[] = 'l.status=?'; $args[] = $status; }
if ($owner)         { $where[] = 'l.assigned_to=?'; $args[] = $owner; }
if ($due === '1')   { $where[] = 'l.next_followup IS NOT NULL AND l.next_followup<=CURDATE()
                                 AND l.status NOT IN ("converted","lost")'; }
if ($search !== '') {
    $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.city LIKE ?)';
    $l = "%$search%"; array_push($args, $l, $l, $l, $l);
}
$w = implode(' AND ', $where);

$total = (int) scalar("SELECT COUNT(*) FROM leads l WHERE $w", $args);
$rows  = all("SELECT l.*, u.name AS owner_name FROM leads l LEFT JOIN users u ON u.id=l.assigned_to
              WHERE $w ORDER BY l.next_followup IS NULL, l.next_followup ASC, l.id DESC
              LIMIT $limit OFFSET $offset", $args);

$pipeline = [];
foreach (all('SELECT status, COUNT(*) c FROM leads GROUP BY status') as $r) $pipeline[$r['status']] = (int)$r['c'];
$dueCount = (int) scalar('SELECT COUNT(*) FROM leads WHERE next_followup<=CURDATE()
                          AND status NOT IN ("converted","lost")');
$staff = all('SELECT id,name FROM users WHERE status="active" ORDER BY name');

$labels = ['new' => 'New', 'contacted' => 'Contacted', 'follow_up' => 'Follow up',
           'visit_scheduled' => 'Visit booked', 'converted' => 'Converted', 'lost' => 'Lost'];

layout_header('Leads');
?>
<div class="desk-bar page-h"><h1>Leads</h1><a class="btn btn-red" href="lead_edit.php"><?= icon('plus', 16) ?>  Add lead</a></div>

<?php if ($dueCount): ?>
  <div class="flash flash-warn"><span><b><?= $dueCount ?></b> follow-up<?= $dueCount > 1 ? 's are' : ' is' ?> due today or overdue.
    <a href="?due=1">Show them &rarr;</a></span></div>
<?php endif; ?>

<div class="stats" style="grid-template-columns:repeat(3,1fr)">
  <?php foreach (['new' => 'blue', 'follow_up' => 'amber', 'converted' => 'green'] as $k => $cls): ?>
    <a class="stat <?= $cls ?>" href="?status=<?= $k ?>">
      <span class="k"><?= e($labels[$k]) ?></span><span class="v"><?= $pipeline[$k] ?? 0 ?></span></a>
  <?php endforeach; ?>
</div>

<div class="toolbar"><form method="get" data-autosubmit>
  <input class="grow" type="search" name="q" placeholder="Name, mobile, city…" value="<?= e($search) ?>">
  <select name="status">
    <option value="">All stages</option>
    <?php foreach ($labels as $k => $lb): ?>
      <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lb ?> (<?= $pipeline[$k] ?? 0 ?>)</option>
    <?php endforeach; ?>
  </select>
  <select name="owner">
    <option value="">Anyone</option>
    <?php foreach ($staff as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= $owner === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($due === '1'): ?><input type="hidden" name="due" value="1"><?php endif; ?>
  <button class="btn btn-ghost btn-s">Filter</button>
</form></div>

<div class="card">
  <div class="card-h"><h2><?= $total ?> lead<?= $total === 1 ? '' : 's' ?></h2>
    <?php if ($due === '1'): ?><a class="btn btn-s btn-ghost" href="leads.php">Clear due filter</a><?php endif; ?></div>
  <?php if (!$rows): ?>
    <div class="empty"><div class="big-icon"><?= icon('trend', 44) ?></div>No leads match.<br><a href="lead_edit.php">Add one</a></div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="stack">
    <thead><tr><th>Lead</th><th>Interest</th><th>Owner</th><th>Follow-up</th><th>Stage</th></tr></thead>
    <tbody><?php foreach ($rows as $l):
      $overdue = $l['next_followup'] && $l['next_followup'] <= today()
                 && !in_array($l['status'], ['converted', 'lost'], true); ?>
      <tr>
        <td data-l="Lead"><a href="lead_view.php?id=<?= (int)$l['id'] ?>"><b><?= e($l['name']) ?></b></a><br>
          <span class="small muted"><?= e($l['phone']) ?><?= $l['city'] ? ' &middot; ' . e($l['city']) : '' ?></span></td>
        <td data-l="Interest"><span class="pill"><?= e(ucfirst($l['interest'])) ?></span>
          <?php if ($l['plan_interest']): ?><br><span class="small muted"><?= e($l['plan_interest']) ?></span><?php endif; ?></td>
        <td data-l="Owner"><?= e($l['owner_name'] ?: 'Unassigned') ?><br>
          <span class="small muted"><?= e($l['source'] ?: '') ?></span></td>
        <td data-l="Follow-up"><?= $l['next_followup']
              ? ($overdue ? '<b style="color:var(--red-600)">' . dmy($l['next_followup']) . '</b>' : dmy($l['next_followup']))
              : '<span class="muted">—</span>' ?></td>
        <td data-l="Stage"><?= status_badge($l['status']) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?= pager_links($total, $limit, $page, ['q' => $search, 'status' => $status, 'owner' => $owner, 'due' => $due]) ?>
<a class="fab" href="lead_edit.php" aria-label="Add lead"><?= icon('plus', 16) ?> </a>
<?php layout_footer(); ?>

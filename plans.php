<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('plans');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = pstr('action');
    $id  = pint('id');

    if ($act === 'save') {
        $orig = pdec('original_amt');
        $disc = pdec('discount_pct');
        $amt  = pdec('amount');
        if ($amt <= 0 && $orig > 0) $amt = round($orig * (100 - $disc) / 100, 2);
        if ($orig > 0 && $amt > 0)  $disc = round(($orig - $amt) * 100 / $orig, 2);

        $data = [
            'name'          => pstr('name'),
            'audience'      => pstr('audience', 'all'),
            'kind'          => pstr('kind', 'package'),
            'sessions'      => max(1, pint('sessions')),
            'duration_min'  => max(5, pint('duration_min')),
            'original_amt'  => $orig,
            'discount_pct'  => max(0, $disc),
            'amount'        => $amt,
            'validity_days' => max(1, pint('validity_days')),
            'sort_order'    => pint('sort_order'),
            'status'        => pstr('status', 'active'),
        ];
        if ($data['name'] === '') { flash('Plan name is required.', 'error'); redirect('plans.php'); }
        if ($id) { update('plans', $data, 'id=?', [$id]); flash('Plan updated.'); }
        else     { $data['created_at'] = now(); insert('plans', $data); flash('Plan added.'); }
    }
    if ($act === 'toggle') {
        $p = one('SELECT status FROM plans WHERE id=?', [$id]);
        if ($p) update('plans', ['status' => $p['status'] === 'active' ? 'inactive' : 'active'], 'id=?', [$id]);
        flash('Plan status changed.', 'info');
    }
    redirect('plans.php');
}

$edit  = gint('edit') ? one('SELECT * FROM plans WHERE id=?', [gint('edit')]) : null;
$plans = all('SELECT * FROM plans ORDER BY audience, sort_order, id');
$v = fn($k, $d = '') => e($edit[$k] ?? $d);
$sel = fn($k, $val, $d = '') => (($edit[$k] ?? $d) == $val ? 'selected' : '');

layout_header('Plans & fees');
?>
<div class="desk-bar page-h"><h1>Plans &amp; fees</h1></div>

<div class="split wide">
  <div>
  <?php
  $groups = ['child' => 'Fees for Children (under 18 years)', 'adult' => 'Fees for Adults (over 18 years)', 'all' => 'Other plans'];
  foreach ($groups as $aud => $title):
      $rows = array_values(array_filter($plans, fn($p) => $p['audience'] === $aud));
      if (!$rows) continue; ?>
    <div class="card">
      <div class="card-h"><h2><?= e($title) ?></h2></div>
      <div class="tbl-wrap"><table class="stack">
        <thead><tr><th>Description</th><th class="num">Sessions</th><th class="num">Original</th>
          <th class="num">Offer price</th><th class="num">Duration</th><th></th></tr></thead>
        <tbody><?php foreach ($rows as $p): ?>
          <tr style="<?= $p['status'] === 'inactive' ? 'opacity:.55' : '' ?>">
            <td data-l="Description"><b><?= e($p['name']) ?></b>
              <?php if ($p['kind'] === 'guest'): ?> <span class="pill">Guest</span><?php endif; ?>
              <?php if ($p['status'] === 'inactive'): ?> <span class="badge badge-muted">Off</span><?php endif; ?>
              <br><span class="small muted">Valid <?= (int)$p['validity_days'] ?> days</span></td>
            <td data-l="Sessions" class="num"><?= (int)$p['sessions'] ?></td>
            <td data-l="Original" class="num"><span class="muted"><?= money($p['original_amt']) ?></span></td>
            <td data-l="Offer price" class="num"><b style="color:var(--red-600)"><?= money($p['amount']) ?></b>
              <?php if ((float)$p['discount_pct'] > 0): ?><br><span class="small muted"><?= rtrim(rtrim(number_format((float)$p['discount_pct'], 2), '0'), '.') ?>% off</span><?php endif; ?></td>
            <td data-l="Duration" class="num"><?= (int)$p['duration_min'] ?> min</td>
            <td data-l="" class="num nowrap">
              <a class="btn btn-s btn-ghost" href="?edit=<?= (int)$p['id'] ?>">Edit</a>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-s btn-ghost"><?= $p['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
  <?php endforeach; ?>
  </div>

  <div class="card" id="form">
    <div class="card-h"><h3><?= $edit ? 'Edit plan' : 'Add a plan' ?></h3></div>
    <form method="post"><div class="card-b">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="field"><label>Description <span class="req">*</span></label>
        <input type="text" name="name" required placeholder="Monthly - Child" value="<?= $v('name') ?>"></div>
      <div class="grid-2">
        <div class="field"><label>Applies to</label>
          <select name="audience">
            <option value="child" <?= $sel('audience', 'child') ?>>Children</option>
            <option value="adult" <?= $sel('audience', 'adult') ?>>Adults</option>
            <option value="all"   <?= $sel('audience', 'all', 'all') ?>>Everyone</option>
          </select></div>
        <div class="field"><label>Type</label>
          <select name="kind">
            <option value="package" <?= $sel('kind', 'package', 'package') ?>>Package</option>
            <option value="guest"   <?= $sel('kind', 'guest') ?>>Guest ride</option>
          </select></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Sessions</label>
          <input type="number" name="sessions" min="1" value="<?= $v('sessions', '1') ?>"></div>
        <div class="field"><label>Class duration (min)</label>
          <input type="number" name="duration_min" min="5" step="5" value="<?= $v('duration_min', '30') ?>"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Original amount</label>
          <input type="number" name="original_amt" step="0.01" min="0" value="<?= $v('original_amt', '0') ?>"></div>
        <div class="field"><label>Discount %</label>
          <input type="number" name="discount_pct" step="0.01" min="0" max="100" value="<?= $v('discount_pct', '30') ?>"></div>
      </div>
      <div class="field"><label>Payable amount</label>
        <input type="number" name="amount" step="0.01" min="0" value="<?= $v('amount', '0') ?>">
        <div class="help">Leave 0 to calculate it from the original amount and discount.</div></div>
      <div class="grid-2">
        <div class="field"><label>Validity (days)</label>
          <input type="number" name="validity_days" min="1" value="<?= $v('validity_days', '30') ?>"></div>
        <div class="field"><label>Sort order</label>
          <input type="number" name="sort_order" value="<?= $v('sort_order', '0') ?>"></div>
      </div>
      <div class="field"><label>Status</label>
        <select name="status">
          <option value="active"   <?= $sel('status', 'active', 'active') ?>>Active</option>
          <option value="inactive" <?= $sel('status', 'inactive') ?>>Inactive</option>
        </select></div>
    </div>
    <div class="card-f btn-row">
      <button class="btn btn-red" type="submit"><?= $edit ? 'Save plan' : 'Add plan' ?></button>
      <?php if ($edit): ?><a class="btn btn-ghost" href="plans.php">Cancel</a><?php endif; ?>
    </div></form>
  </div>
</div>
<?php layout_footer(); ?>

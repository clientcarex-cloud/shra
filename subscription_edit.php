<?php
/** Start a package for a rider, optionally raising the invoice in the same step. */
require_once __DIR__ . '/inc/bootstrap.php';
require_can('subscriptions');

$customerId = gint('customer_id');
$customer   = $customerId ? find_customer($customerId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cid    = pint('customer_id');
    $planId = pint('plan_id');
    $cust   = find_customer($cid);
    $plan   = $planId ? one('SELECT * FROM plans WHERE id=?', [$planId]) : null;

    if (!$cust)  { flash('Choose a rider first.', 'error'); redirect('subscription_edit.php'); }
    if (!$plan && pstr('plan_name') === '') { flash('Choose a plan.', 'error'); redirect('subscription_edit.php?customer_id=' . $cid); }

    $price = pdec('price');
    if ($price <= 0 && $plan) $price = (float)$plan['amount'];

    db()->beginTransaction();
    try {
        $subId = create_subscription($cid, [
            'plan_id'        => $planId ?: null,
            'plan_name'      => $plan['name'] ?? pstr('plan_name'),
            'trainer_id'     => pint('trainer_id') ?: null,
            'start_date'     => pstr('start_date', today()),
            'total_sessions' => pint('total_sessions') ?: ($plan['sessions'] ?? 1),
            'duration_min'   => pint('duration_min') ?: ($plan['duration_min'] ?? 30),
            'validity_days'  => pint('validity_days') ?: ($plan['validity_days'] ?? 30),
            'price'          => $price,
            'notes'          => pstr('notes'),
        ]);

        $invId = null;
        if (pstr('create_invoice') === '1' && $price > 0) {
            $invId = create_invoice($cid, [[
                'plan_id'     => $planId ?: null,
                'description' => ($plan['name'] ?? pstr('plan_name')) . ' — '
                               . (pint('total_sessions') ?: ($plan['sessions'] ?? 1)) . ' sessions',
                'qty'         => 1,
                'rate'        => $price,
            ]], [
                'subscription_id' => $subId,
                'tax_pct'         => (float) setting('tax_pct', 0),
                'notes'           => 'Package subscription',
            ]);
            $paidNow = pdec('paid_now');
            if ($paidNow > 0) add_payment($invId, $paidNow, pstr('pay_mode', 'cash'), ['reference' => pstr('pay_ref')]);
        }
        db()->commit();
        log_activity('subscription', $subId, 'created');
        flash('Package started.');
        redirect($invId ? 'invoice_view.php?id=' . $invId : 'subscription_view.php?id=' . $subId);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash('Could not start the package: ' . e($e->getMessage()), 'error');
        redirect('subscription_edit.php?customer_id=' . $cid);
    }
}

$plans    = plan_options($customer['category'] ?? null);
$trainers = trainer_options();
layout_header('New package');
?>
<div class="desk-bar page-h"><h1>Start a package</h1></div>
<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-h"><h2>Rider</h2></div>
    <div class="card-b">
      <?php if ($customer): ?>
        <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
        <div class="list-item" style="padding-left:0">
          <span class="avatar-lg"><?= e(initials(customer_name($customer))) ?></span>
          <div class="g"><b><?= e(customer_name($customer)) ?></b>
            <span><?= e($customer['code']) ?> &middot; <?= e($customer['phone']) ?>
              &middot; <?= e(ucfirst($customer['category'])) ?> fees</span></div>
          <a class="btn btn-s btn-ghost" href="subscription_edit.php">Change</a>
        </div>
      <?php else: ?>
        <div class="field"><label>Search rider <span class="req">*</span></label>
          <input type="text" data-cust-search="cust-id" placeholder="Type a name or mobile number…" autocomplete="off">
          <input type="hidden" name="customer_id" id="cust-id" required>
          <div class="help">Not registered yet? <a href="customer_edit.php">Add the rider first</a>.</div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Package</h2></div>
    <div class="card-b">
      <div class="field"><label>Plan</label>
        <select name="plan_id" id="plan">
          <option value="">— Custom package —</option>
          <?php foreach ($plans as $p): ?>
            <option value="<?= (int)$p['id'] ?>"
              data-sessions="<?= (int)$p['sessions'] ?>" data-dur="<?= (int)$p['duration_min'] ?>"
              data-price="<?= e($p['amount']) ?>" data-valid="<?= (int)$p['validity_days'] ?>"
              data-name="<?= e($p['name']) ?>">
              <?= e($p['name']) ?> — <?= (int)$p['sessions'] ?> sessions — <?= money($p['amount']) ?>
            </option>
          <?php endforeach; ?>
        </select></div>
      <div class="field" id="custom-name" style="display:none"><label>Custom plan name</label>
        <input type="text" name="plan_name" placeholder="e.g. Summer camp"></div>
      <div class="grid-2">
        <div class="field"><label>Total sessions</label>
          <input type="number" name="total_sessions" id="f-sessions" min="1" value="1"></div>
        <div class="field"><label>Class duration (min)</label>
          <input type="number" name="duration_min" id="f-dur" min="5" step="5" value="30"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Start date</label>
          <input type="date" name="start_date" value="<?= today() ?>"></div>
        <div class="field"><label>Validity (days)</label>
          <input type="number" name="validity_days" id="f-valid" min="1" value="30"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Price</label>
          <input type="number" name="price" id="f-price" step="0.01" min="0" value="0"></div>
        <div class="field"><label>Assign trainer</label>
          <select name="trainer_id">
            <option value="">— Not assigned —</option>
            <?php foreach ($trainers as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="field"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
    </div>
  </div>

  <?php if (can('billing')): ?>
  <div class="card">
    <div class="card-h"><h2>Billing</h2></div>
    <div class="card-b">
      <label class="check"><input type="checkbox" name="create_invoice" value="1" checked id="mk-inv">
        <span>Raise an invoice for this package now</span></label>
      <div id="pay-box">
        <div class="grid-2">
          <div class="field"><label>Amount collected now</label>
            <input type="number" name="paid_now" step="0.01" min="0" value="0"></div>
          <div class="field"><label>Payment mode</label>
            <select name="pay_mode">
              <option value="cash">Cash</option><option value="upi">UPI</option>
              <option value="card">Card</option><option value="bank">Bank transfer</option>
            </select></div>
        </div>
        <div class="field"><label>Reference / UTR</label><input type="text" name="pay_ref"></div>
      </div>
    </div>
    <div class="card-f"><button class="btn btn-red btn-block" type="submit">Start package</button></div>
  </div>
  <?php else: ?>
    <div class="card"><div class="card-f"><button class="btn btn-red btn-block" type="submit">Start package</button></div></div>
  <?php endif; ?>
</form>
<script>
(function () {
  var plan = document.getElementById('plan');
  if (!plan) return;
  plan.addEventListener('change', function () {
    var o = plan.selectedOptions[0];
    document.getElementById('custom-name').style.display = plan.value ? 'none' : '';
    if (!plan.value) return;
    document.getElementById('f-sessions').value = o.dataset.sessions;
    document.getElementById('f-dur').value      = o.dataset.dur;
    document.getElementById('f-price').value    = o.dataset.price;
    document.getElementById('f-valid').value    = o.dataset.valid;
  });
  var mk = document.getElementById('mk-inv'), box = document.getElementById('pay-box');
  if (mk) mk.addEventListener('change', function () { box.style.display = mk.checked ? '' : 'none'; });
})();
</script>
<?php layout_footer(); ?>

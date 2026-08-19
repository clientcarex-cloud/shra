<?php
/** Walk-in guest ride: register (or find) the rider, log the ride, bill it, take payment. */
require_once __DIR__ . '/inc/bootstrap.php';
require_can('guest');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = pstr('rider_mode', 'new');

    db()->beginTransaction();
    try {
        /* ---- 1. rider ---- */
        if ($mode === 'existing') {
            $cid  = pint('customer_id');
            $cust = find_customer($cid);
            if (!$cust) throw new RuntimeException('Select an existing rider or switch to "New rider".');
        } else {
            $first = pstr('first_name');
            $phone = pstr('phone');
            if ($first === '' || $phone === '') throw new RuntimeException('Rider name and mobile number are required.');

            $dob = pstr('dob') ?: null;
            $age = age_from($dob);
            $cat = $age !== null ? ($age < 18 ? 'child' : 'adult') : pstr('category', 'adult');

            $cid = insert('customers', [
                'code'         => next_customer_code(),
                'first_name'   => $first,
                'last_name'    => pstr('last_name'),
                'guardian_name'=> pstr('guardian_name'),
                'dob'          => $dob,
                'phone'        => $phone,
                'email'        => pstr('email'),
                'city'         => pstr('city'),
                'category'     => $cat,
                'riding_level' => 'beginner',
                'source'       => pstr('source', 'Walk-in'),
                'portal_pin'   => (string) random_int(1000, 9999),
                'status'       => 'active',
                'created_by'   => current_user()['id'],
                'created_at'   => now(),
            ]);
            $cust = find_customer($cid);
            log_activity('customer', $cid, 'created via guest ride');
        }

        /* ---- 2. plan / price ---- */
        $planId = pint('plan_id') ?: null;
        $plan   = $planId ? one('SELECT * FROM plans WHERE id=?', [$planId]) : null;
        $rate   = pdec('rate');
        if ($rate <= 0 && $plan) $rate = (float)$plan['amount'];
        $qty    = max(1, pint('qty', 1));
        $desc   = $plan['name'] ?? pstr('description', 'Guest ride');

        /* ---- 3. the ride itself ---- */
        for ($i = 0; $i < $qty; $i++) {
            mark_attendance([
                'customer_id'  => $cid,
                'ride_type'    => 'guest',
                'trainer_id'   => pint('trainer_id') ?: null,
                'horse_name'   => pstr('horse_name'),
                'ride_date'    => pstr('ride_date', today()),
                'ride_time'    => pstr('ride_time') ?: date('H:i'),
                'duration_min' => pint('duration_min') ?: (int)($plan['duration_min'] ?? 20),
                'status'       => 'present',
                'remarks'      => pstr('remarks'),
            ]);
        }

        /* ---- 4. bill ---- */
        $invId = null;
        if ($rate > 0 && can('billing')) {
            $invId = create_invoice($cid, [[
                'plan_id'     => $planId,
                'description' => $desc,
                'qty'         => $qty,
                'rate'        => $rate,
            ]], [
                'tax_pct' => (float) setting('tax_pct', 0),
                'notes'   => 'Guest ride',
            ]);
            $paid = pdec('paid_now');
            if ($paid > 0) add_payment($invId, $paid, pstr('pay_mode', 'cash'), ['reference' => pstr('pay_ref')]);
        }

        db()->commit();
        flash('Guest ride recorded for <b>' . e(customer_name($cust)) . '</b>.');
        redirect($invId ? 'invoice_view.php?id=' . $invId : 'customer_view.php?id=' . $cid);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash(e($e->getMessage()), 'error');
        redirect('guest_ride.php');
    }
}

$pre       = gint('customer_id') ? find_customer(gint('customer_id')) : null;
$guestPlans = all('SELECT * FROM plans WHERE status="active" AND kind="guest" ORDER BY audience, sort_order');
$allPlans   = plan_options();

layout_header('Guest ride');
?>
<div class="desk-bar page-h"><h1>Guest ride</h1></div>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-h"><h2>Rider</h2></div>
    <div class="card-b">
      <div class="radio-row mb">
        <label><input type="radio" name="rider_mode" value="new" <?= $pre ? '' : 'checked' ?> data-mode="new"><span>New rider</span></label>
        <label><input type="radio" name="rider_mode" value="existing" <?= $pre ? 'checked' : '' ?> data-mode="existing"><span>Existing rider</span></label>
      </div>

      <div id="box-existing" style="<?= $pre ? '' : 'display:none' ?>">
        <?php if ($pre): ?>
          <div class="list-item" style="padding-left:0">
            <span class="avatar-lg"><?= e(initials(customer_name($pre))) ?></span>
            <div class="g"><b><?= e(customer_name($pre)) ?></b>
              <span><?= e($pre['code']) ?> &middot; <?= e($pre['phone']) ?></span></div>
            <a class="btn btn-s btn-ghost" href="guest_ride.php">Change</a>
          </div>
          <input type="hidden" name="customer_id" id="g-cust" value="<?= (int)$pre['id'] ?>">
        <?php else: ?>
          <div class="field"><label>Search rider</label>
            <input type="text" data-cust-search="g-cust" placeholder="Name or mobile…" autocomplete="off">
            <input type="hidden" name="customer_id" id="g-cust"></div>
        <?php endif; ?>
      </div>

      <div id="box-new" style="<?= $pre ? 'display:none' : '' ?>">
        <div class="grid-2">
          <div class="field"><label>First name <span class="req">*</span></label>
            <input type="text" name="first_name"></div>
          <div class="field"><label>Surname</label><input type="text" name="last_name"></div>
        </div>
        <div class="grid-2">
          <div class="field"><label>Mobile <span class="req">*</span></label>
            <input type="tel" name="phone"></div>
          <div class="field"><label>Date of birth</label><input type="date" name="dob">
            <div class="help">Decides child / adult pricing.</div></div>
        </div>
        <div class="grid-2">
          <div class="field"><label>Fee category</label>
            <select name="category"><option value="adult">Adult</option><option value="child">Child</option></select></div>
          <div class="field"><label>City</label><input type="text" name="city"></div>
        </div>
        <div class="grid-2">
          <div class="field"><label>Guardian (for minors)</label><input type="text" name="guardian_name"></div>
          <div class="field"><label>Email</label><input type="email" name="email"></div>
        </div>
        <div class="field"><label>Source</label>
          <input type="text" name="source" list="src" value="Walk-in">
          <datalist id="src"><option>Walk-in</option><option>Instagram</option><option>Google</option>
            <option>Referral</option><option>Event</option></datalist></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Ride</h2></div>
    <div class="card-b">
      <div class="field"><label>Plan</label>
        <select name="plan_id" id="g-plan">
          <optgroup label="Guest rides">
            <?php foreach ($guestPlans as $p): ?>
              <option value="<?= (int)$p['id'] ?>" data-rate="<?= e($p['amount']) ?>" data-dur="<?= (int)$p['duration_min'] ?>">
                <?= e($p['name']) ?> — <?= money($p['amount']) ?> / <?= (int)$p['duration_min'] ?> min</option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="Other plans">
            <?php foreach ($allPlans as $p): if ($p['kind'] === 'guest') continue; ?>
              <option value="<?= (int)$p['id'] ?>" data-rate="<?= e($p['amount']) ?>" data-dur="<?= (int)$p['duration_min'] ?>">
                <?= e($p['name']) ?> — <?= money($p['amount']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <option value="">— Custom —</option>
        </select></div>
      <div class="grid-2">
        <div class="field"><label>Number of rides</label>
          <input type="number" name="qty" id="g-qty" min="1" value="1"></div>
        <div class="field"><label>Price per ride</label>
          <input type="number" name="rate" id="g-rate" step="0.01" min="0" value="0"></div>
      </div>
      <div class="field" id="g-desc-box" style="display:none"><label>Description</label>
        <input type="text" name="description" placeholder="Guest ride"></div>
      <div class="grid-2">
        <div class="field"><label>Date</label><input type="date" name="ride_date" value="<?= today() ?>"></div>
        <div class="field"><label>Time</label><input type="time" name="ride_time" value="<?= date('H:i') ?>"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Trainer</label>
          <select name="trainer_id">
            <option value="">— Not assigned —</option>
            <?php foreach (trainer_options() as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>Horse</label><input type="text" name="horse_name"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Duration (min)</label>
          <input type="number" name="duration_min" id="g-dur" min="5" step="5" value="20"></div>
        <div class="field"><label>Remarks</label><input type="text" name="remarks"></div>
      </div>
    </div>
  </div>

  <?php if (can('billing')): ?>
  <div class="card">
    <div class="card-h"><h2>Payment</h2></div>
    <div class="card-b">
      <div class="grid-2">
        <div class="field"><label>Collected now</label>
          <input type="number" name="paid_now" id="g-paid" step="0.01" min="0" value="0">
          <div class="help">Leave 0 to bill now and collect later — the invoice carries a pay-by-QR code.</div></div>
        <div class="field"><label>Mode</label>
          <select name="pay_mode">
            <option value="cash">Cash</option><option value="upi">UPI</option>
            <option value="card">Card</option><option value="bank">Bank transfer</option>
          </select></div>
      </div>
      <div class="field"><label>Reference / UTR</label><input type="text" name="pay_ref"></div>
    </div>
    <div class="card-f"><button class="btn btn-red btn-block" type="submit">Save ride &amp; generate bill</button></div>
  </div>
  <?php else: ?>
    <div class="card"><div class="card-f"><button class="btn btn-red btn-block" type="submit">Save ride</button></div></div>
  <?php endif; ?>
</form>

<script>
(function () {
  function sync() {
    var m = document.querySelector('input[name=rider_mode]:checked').value;
    document.getElementById('box-new').style.display      = m === 'new' ? '' : 'none';
    document.getElementById('box-existing').style.display = m === 'existing' ? '' : 'none';
  }
  document.querySelectorAll('input[name=rider_mode]').forEach(function (r) { r.addEventListener('change', sync); });

  var plan = document.getElementById('g-plan');
  function fill() {
    var o = plan.selectedOptions[0];
    document.getElementById('g-desc-box').style.display = plan.value ? 'none' : '';
    if (!plan.value) return;
    document.getElementById('g-rate').value = o.dataset.rate;
    document.getElementById('g-dur').value  = o.dataset.dur;
    total();
  }
  function total() {
    var q = parseFloat(document.getElementById('g-qty').value) || 1,
        r = parseFloat(document.getElementById('g-rate').value) || 0,
        p = document.getElementById('g-paid');
    if (p) p.value = (q * r).toFixed(2);
  }
  plan.addEventListener('change', fill);
  ['g-qty', 'g-rate'].forEach(function (id) { document.getElementById(id).addEventListener('input', total); });
  fill();
})();
</script>
<?php layout_footer(); ?>

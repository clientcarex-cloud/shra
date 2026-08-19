<?php
/**
 * Self-billing kiosk. A rider scans the counter QR, picks a plan and gets an
 * invoice with a UPI QR — no staff, no login.
 *
 * Privacy note: an existing rider is matched on mobile number but no stored
 * details are ever echoed back, so the page cannot be used to probe the
 * customer list.
 */
require_once __DIR__ . '/inc/bootstrap.php';

if (setting('self_billing', '1') !== '1') {
    plain_header('Self billing');
    echo '<div class="auth"><div class="auth-card center"><div class="auth-logo">' . logo_svg() . '</div>'
       . '<h2>Self billing is off</h2><p class="muted">Please pay at the front desk.</p></div></div>';
    plain_footer();
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name   = pstr('name');
    $phone  = preg_replace('/\s+/', '', pstr('phone'));
    $planId = pint('plan_id');
    $qty    = max(1, min(20, pint('qty', 1)));
    $plan   = $planId ? one('SELECT * FROM plans WHERE id=? AND status="active"', [$planId]) : null;

    if ($name === '' || strlen(preg_replace('/\D+/', '', $phone)) < 10) {
        $error = 'Please enter your name and a valid 10-digit mobile number.';
    } elseif (!$plan) {
        $error = 'Please choose a plan.';
    } else {
        db()->beginTransaction();
        try {
            $digits = preg_replace('/\D+/', '', $phone);
            $cust = one('SELECT * FROM customers WHERE REPLACE(REPLACE(phone," ",""),"-","") LIKE ? LIMIT 1',
                        ['%' . substr($digits, -10)]);

            if (!$cust) {
                $parts = preg_split('/\s+/', $name, 2);
                $cid = insert('customers', [
                    'code'       => next_customer_code(),
                    'first_name' => $parts[0],
                    'last_name'  => $parts[1] ?? '',
                    'phone'      => $phone,
                    'category'   => $plan['audience'] === 'child' ? 'child' : 'adult',
                    'riding_level' => 'beginner',
                    'source'     => 'Self billing QR',
                    'portal_pin' => (string) random_int(1000, 9999),
                    'status'     => 'active',
                    'created_at' => now(),
                ]);
            } else {
                $cid = (int)$cust['id'];
            }

            $invId = create_invoice($cid, [[
                'plan_id'     => $plan['id'],
                'description' => $plan['name'],
                'qty'         => $qty,
                'rate'        => (float)$plan['amount'],
            ]], [
                'source'     => 'self',
                'tax_pct'    => (float) setting('tax_pct', 0),
                'notes'      => 'Created by the rider from the self-billing QR',
                'created_by' => null,
            ]);

            // A package plan also starts the subscription so sessions are tracked.
            if ($plan['kind'] === 'package') {
                $subId = create_subscription($cid, [
                    'plan_id'        => $plan['id'],
                    'plan_name'      => $plan['name'],
                    'total_sessions' => (int)$plan['sessions'] * $qty,
                    'duration_min'   => (int)$plan['duration_min'],
                    'validity_days'  => (int)$plan['validity_days'],
                    'price'          => (float)$plan['amount'] * $qty,
                    'notes'          => 'Started from self-billing QR — confirm payment at the desk',
                ]);
                update('invoices', ['subscription_id' => $subId], 'id=?', [$invId]);
            }

            $token = (string) scalar('SELECT token FROM invoices WHERE id=?', [$invId]);
            db()->commit();
            redirect('pay.php?t=' . urlencode($token));
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $error = 'Something went wrong. Please ask the front desk for help.';
        }
    }
}

$plans = all('SELECT * FROM plans WHERE status="active" ORDER BY audience, sort_order, id');
plain_header('Book & pay');
?>
<div class="app"><main class="main" style="max-width:620px;margin:0 auto">
  <div class="center" style="color:var(--brown-800);padding:1.2rem 0 .2rem">
    <div style="width:84px;margin:0 auto"><?= logo_svg() ?></div>
    <h1 style="font-size:1.1rem;margin:.5rem 0 .1rem"><?= e(setting('academy_name', APP_NAME)) ?></h1>
    <p class="small muted" style="letter-spacing:.14em;text-transform:uppercase">Book &amp; pay yourself</p>
  </div>

  <?php if ($error): ?><div class="flash flash-error"><span><?= e($error) ?></span></div><?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>
    <div class="card">
      <div class="card-h"><h2>Your details</h2></div>
      <div class="card-b">
        <div class="field"><label>Full name <span class="req">*</span></label>
          <input type="text" name="name" required value="<?= e(post('name', '')) ?>"></div>
        <div class="field"><label>Mobile number <span class="req">*</span></label>
          <input type="tel" name="phone" required inputmode="numeric" value="<?= e(post('phone', '')) ?>">
          <div class="help">Already registered with us? Use the same number and this bill joins your account.</div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><h2>Choose a plan</h2></div>
      <div class="card-b">
        <?php
        $groups = ['child' => 'For children (under 18)', 'adult' => 'For adults (over 18)', 'all' => 'Other'];
        foreach ($groups as $aud => $title):
            $rows = array_values(array_filter($plans, fn($p) => $p['audience'] === $aud));
            if (!$rows) continue; ?>
          <div class="lbl" style="margin-top:.6rem"><?= e($title) ?></div>
          <div class="radio-row" style="flex-direction:column;align-items:stretch">
            <?php foreach ($rows as $p): ?>
              <label style="justify-content:space-between">
                <span style="display:flex;align-items:center;gap:.5rem">
                  <input type="radio" name="plan_id" value="<?= (int)$p['id'] ?>"
                         <?= (int)post('plan_id') === (int)$p['id'] ? 'checked' : '' ?> required>
                  <span><b><?= e($p['name']) ?></b><br>
                    <span class="small muted"><?= (int)$p['sessions'] ?> session<?= $p['sessions'] > 1 ? 's' : '' ?>
                      &middot; <?= (int)$p['duration_min'] ?> min</span></span>
                </span>
                <span class="nowrap"><b style="color:var(--red-600)"><?= money($p['amount']) ?></b>
                  <?php if ((float)$p['original_amt'] > (float)$p['amount']): ?>
                    <br><span class="small muted" style="text-decoration:line-through"><?= money($p['original_amt']) ?></span>
                  <?php endif; ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <div class="field mt"><label>Quantity</label>
          <input type="number" name="qty" min="1" max="20" value="1"></div>
      </div>
      <div class="card-f"><button class="btn btn-red btn-block">Continue to payment</button></div>
    </div>
  </form>

  <p class="center small muted">Need help? Call <?= e(setting('academy_phone', '')) ?><br>
    <?= e(setting('academy_address', '')) ?></p>
</main></div>
<?php plain_footer(); ?>

<?php
/**
 * Public invoice page reached by scanning the QR on a bill.
 * Access is by unguessable token only — no login, no listing.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/qrcode.php';

$token = gstr('t');
$inv   = $token ? one('SELECT * FROM invoices WHERE token=?', [$token]) : null;

if (!$inv) {
    http_response_code(404);
    plain_header('Invoice not found');
    echo '<div class="auth"><div class="auth-card center"><div class="auth-logo">' . logo_svg() . '</div>'
       . '<h2>Link not valid</h2><p class="muted">This payment link has expired or is incorrect. '
       . 'Please ask the front desk for a fresh one.</p></div></div>';
    plain_footer();
    exit;
}

$cust    = find_customer((int)$inv['customer_id']);
$balance = invoice_balance($inv);
$done    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inv['status'] !== 'cancelled' && $balance > 0) {
    csrf_check();
    $amt = pdec('amount');
    $ref = pstr('reference');
    if ($amt > 0 && $amt <= $balance + 0.01) {
        add_payment((int)$inv['id'], $amt, pstr('mode', 'upi'), [
            'reference'   => $ref,
            'source'      => 'self',
            'status'      => 'pending',       // the desk verifies before it counts
            'received_by' => null,
            'notes'       => 'Submitted by rider from the payment link',
        ]);
        $done = true;
        $inv  = one('SELECT * FROM invoices WHERE id=?', [$inv['id']]);
    } else {
        $error = 'Enter an amount between ' . APP_CURRENCY . '1 and ' . money($balance) . '.';
    }
}

$items = all('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id', [$inv['id']]);
$upi   = upi_uri($balance > 0 ? $balance : 0, $inv['invoice_no']);

plain_header('Invoice ' . $inv['invoice_no']);
?>
<div class="app"><main class="main" style="max-width:640px;margin:0 auto;padding-bottom:2rem">

  <div class="center" style="color:var(--brown-800);padding:1.2rem 0 .4rem">
    <div style="width:76px;margin:0 auto"><?= logo_svg() ?></div>
    <h1 style="font-size:1.05rem;margin:.4rem 0 0"><?= e(setting('academy_name', APP_NAME)) ?></h1>
    <p class="small muted"><?= e(setting('academy_phone', '')) ?></p>
  </div>

  <?php if ($done): ?>
    <div class="flash flash-success"><span><b>Thank you.</b> Your payment details were sent to the academy.
      The front desk will confirm it shortly and your receipt will update automatically.</span></div>
  <?php elseif (!empty($error)): ?>
    <div class="flash flash-error"><span><?= e($error) ?></span></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-h"><h2>Invoice <?= e($inv['invoice_no']) ?></h2><?= status_badge($inv['status']) ?></div>
    <div class="card-b">
      <dl class="kv">
        <dt>Rider</dt><dd><?= e(customer_name($cust)) ?></dd>
        <dt>Rider code</dt><dd><?= e($cust['code']) ?></dd>
        <dt>Invoice date</dt><dd><?= dmy($inv['issue_date']) ?></dd>
      </dl>
      <hr>
      <div class="tbl-wrap"><table class="inv-items">
        <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead>
        <tbody><?php foreach ($items as $it): ?>
          <tr><td><?= e($it['description']) ?></td>
            <td class="num"><?= rtrim(rtrim(number_format((float)$it['qty'], 2), '0'), '.') ?></td>
            <td class="num"><?= money($it['amount'], false) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <hr>
      <dl class="kv">
        <dt>Total</dt><dd><b><?= money($inv['total']) ?></b></dd>
        <dt>Paid</dt><dd><?= money($inv['paid_amount']) ?></dd>
        <dt><b>Balance due</b></dt>
        <dd style="font-size:1.25rem;color:<?= $balance > 0 ? 'var(--red-600)' : 'var(--green-600)' ?>">
          <b><?= money($balance) ?></b></dd>
      </dl>
    </div>
  </div>

  <?php if ($inv['status'] === 'cancelled'): ?>
    <div class="flash flash-info"><span>This invoice was cancelled. Nothing is payable.</span></div>

  <?php elseif ($balance <= 0): ?>
    <div class="card center pad">
      <div class="big-icon"><?= icon('check', 44) ?></div>
      <h2>Fully paid</h2>
      <p class="muted">Thank you! Show this screen at the front desk if a receipt is needed.</p>
    </div>

  <?php else: ?>
    <?php if ($upi): ?>
      <div class="card">
        <div class="card-h"><h2>Pay by UPI</h2></div>
        <div class="card-b center">
          <div class="qr-box"><?= QRCode::svg($upi, 210, 'M') ?>
            <div class="cap"><?= e(setting('upi_id')) ?></div></div>
          <p class="small mt">Scan with any UPI app, or tap below on your phone.</p>
          <a class="btn btn-red btn-block" href="<?= e($upi) ?>">Pay <?= money($balance) ?> with UPI</a>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-h"><h2><?= $upi ? 'Already paid? Tell us' : 'Confirm your payment' ?></h2></div>
      <form method="post"><div class="card-b">
        <?= csrf_field() ?>
        <div class="field"><label>Amount paid</label>
          <input type="number" name="amount" step="0.01" min="1" max="<?= e(number_format($balance, 2, '.', '')) ?>"
                 value="<?= e(number_format($balance, 2, '.', '')) ?>" required></div>
        <div class="field"><label>Paid using</label>
          <select name="mode">
            <option value="upi">UPI</option><option value="cash">Cash at the academy</option>
            <option value="card">Card</option><option value="bank">Bank transfer</option>
          </select></div>
        <div class="field"><label>Reference / UTR number</label>
          <input type="text" name="reference" placeholder="From your payment app">
          <div class="help">This helps the desk match your payment quickly.</div></div>
      </div>
      <div class="card-f"><button class="btn btn-block">Submit payment details</button></div></form>
    </div>
  <?php endif; ?>

  <p class="center small muted"><?= e(setting('academy_address', '')) ?><br>
    <?= e(setting('academy_website', '')) ?></p>
</main></div>
<?php plain_footer(); ?>

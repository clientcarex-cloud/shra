<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('billing');
require_once __DIR__ . '/inc/qrcode.php';

$id  = gint('id');
$inv = one('SELECT * FROM invoices WHERE id=?', [$id]);
if (!$inv) { flash('Invoice not found.', 'error'); redirect('invoices.php'); }
$cust = find_customer((int)$inv['customer_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = pstr('action');

    if ($act === 'pay') {
        $amt = pdec('amount');
        if ($amt <= 0) { flash('Enter an amount greater than zero.', 'error'); redirect('invoice_view.php?id=' . $id); }
        add_payment($id, $amt, pstr('mode', 'cash'), [
            'reference' => pstr('reference'),
            'paid_at'   => pstr('paid_at') ? pstr('paid_at') . ' ' . date('H:i:s') : now(),
        ]);
        log_activity('invoice', $id, 'payment', money($amt, false));
        flash('Payment of ' . money($amt) . ' recorded.');
    }
    if ($act === 'cancel' && can('payments.verify')) {
        update('invoices', ['status' => 'cancelled'], 'id=?', [$id]);
        flash('Invoice cancelled.', 'info');
    }
    redirect('invoice_view.php?id=' . $id);
}

$items    = all('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id', [$id]);
$payments = all('SELECT p.*, u.name AS staff FROM payments p LEFT JOIN users u ON u.id=p.received_by
                 WHERE p.invoice_id=? ORDER BY p.id', [$id]);
$balance  = invoice_balance($inv);
$payUrl   = base_url('pay.php?t=' . $inv['token']);
$upi      = upi_uri($balance > 0 ? $balance : 0, $inv['invoice_no']);
$name     = customer_name($cust);

layout_header('Invoice ' . $inv['invoice_no']);
?>
<div class="no-print toolbar">
  <a class="btn btn-ghost btn-s" href="invoices.php">&larr; All invoices</a>
  <button class="btn btn-s" data-print><?= icon('print', 16) ?>  Print / PDF</button>
  <?php $wa = preg_replace('/\D+/', '', $cust['phone']);
        $waMsg = rawurlencode("Hello " . $name . ",\n\nYour invoice " . $inv['invoice_no']
                 . " for " . money($inv['total'], false) . " INR from " . setting('academy_name', APP_NAME)
                 . ".\nBalance due: " . money($balance, false) . " INR.\nView & pay: " . $payUrl);
        if ($wa): ?>
    <a class="btn btn-s btn-green" target="_blank" rel="noopener"
       href="https://wa.me/<?= e(strlen($wa) === 10 ? '91' . $wa : $wa) ?>?text=<?= $waMsg ?>">Send on WhatsApp</a>
  <?php endif; ?>
  <button class="btn btn-s btn-ghost" data-copy="<?= e($payUrl) ?>">Copy pay link</button>
</div>

<div class="card">
  <div class="card-b">
    <!-- letterhead -->
    <div class="inv-head">
      <div class="ih-logo"><?= logo_svg() ?></div>
      <div class="ih-who">
        <h2><?= e(setting('academy_name', APP_NAME)) ?></h2>
        <div class="small muted"><?= nl2br(e(setting('academy_address', ''))) ?></div>
        <div class="small muted"><?= e(setting('academy_phone', '')) ?>
          <?php if (setting('academy_email')): ?><br><?= e(setting('academy_email')) ?><?php endif; ?></div>
      </div>
      <div class="ih-meta">
        <div style="font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:var(--ink-soft)">Invoice</div>
        <div style="font-weight:700;font-size:1rem"><?= e($inv['invoice_no']) ?></div>
        <div class="small muted"><?= dmy($inv['issue_date']) ?></div>
        <div style="margin-top:.3rem"><?= status_badge($inv['status']) ?></div>
      </div>
    </div>

    <div class="inv-parties">
      <div>
        <div class="small muted" style="text-transform:uppercase;letter-spacing:.1em;font-size:.68rem">Billed to</div>
        <b><?= e($name) ?></b><br>
        <span class="small"><?= e($cust['code']) ?><br>
          <?= e($cust['phone']) ?><?= $cust['email'] ? '<br>' . e($cust['email']) : '' ?>
          <?= $cust['address'] ? '<br>' . e($cust['address']) : '' ?>
          <?= $cust['city'] ? '<br>' . e($cust['city']) . ' ' . e($cust['postcode']) : '' ?></span>
      </div>
      <div class="right small">
        <div class="muted">Due date</div><b><?= dmy($inv['due_date'] ?: $inv['issue_date']) ?></b>
        <?php if ($inv['subscription_id']): ?>
          <div class="muted mt-s">Package</div>
          <a href="subscription_view.php?id=<?= (int)$inv['subscription_id'] ?>">
            <?= e((string) scalar('SELECT plan_name FROM subscriptions WHERE id=?', [$inv['subscription_id']])) ?></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="tbl-wrap"><table class="inv-items">
      <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
      <tbody><?php foreach ($items as $it): ?>
        <tr><td><?= e($it['description']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format((float)$it['qty'], 2), '0'), '.') ?></td>
          <td class="num"><?= money($it['rate'], false) ?></td>
          <td class="num"><?= money($it['amount'], false) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>

    <div style="display:flex;justify-content:flex-end;margin-top:.9rem">
      <dl class="kv" style="min-width:260px">
        <dt>Subtotal</dt><dd><?= money($inv['subtotal']) ?></dd>
        <?php if ((float)$inv['discount'] > 0): ?>
          <dt>Discount</dt><dd>− <?= money($inv['discount']) ?></dd><?php endif; ?>
        <?php if ((float)$inv['tax_amount'] > 0): ?>
          <dt><?= e(setting('tax_label', 'GST')) ?> (<?= rtrim(rtrim(number_format((float)$inv['tax_pct'], 2), '0'), '.') ?>%)</dt>
          <dd><?= money($inv['tax_amount']) ?></dd><?php endif; ?>
        <dt style="font-size:1rem"><b>Total</b></dt>
        <dd style="font-size:1.2rem"><b><?= money($inv['total']) ?></b></dd>
        <dt>Paid</dt><dd><?= money($inv['paid_amount']) ?></dd>
        <dt><b>Balance due</b></dt>
        <dd style="color:<?= $balance > 0 ? 'var(--red-600)' : 'var(--green-600)' ?>"><b><?= money($balance) ?></b></dd>
      </dl>
    </div>

    <?php if ($inv['status'] !== 'cancelled'): ?>
    <div style="display:flex;gap:1.2rem;flex-wrap:wrap;align-items:flex-start;margin-top:1rem;
                border-top:1px solid var(--line);padding-top:1rem">
      <div class="qr-box"><?= QRCode::svg($payUrl, 150, 'M') ?>
        <div class="cap">Scan to view &amp; pay</div></div>
      <?php if ($upi && $balance > 0): ?>
        <div class="qr-box"><?= QRCode::svg($upi, 150, 'M') ?>
          <div class="cap">UPI &mdash; <?= money($balance) ?></div></div>
      <?php endif; ?>
      <div style="flex:1;min-width:190px">
        <?php if (setting('terms')): ?>
          <div class="small muted" style="text-transform:uppercase;letter-spacing:.1em;font-size:.68rem">Terms</div>
          <div class="small"><?= nl2br(e(setting('terms'))) ?></div>
        <?php endif; ?>
        <?php if ($inv['notes']): ?><p class="small mt"><b>Note:</b> <?= nl2br(e($inv['notes'])) ?></p><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <p class="center small muted" style="margin-top:1.2rem">
      This is a computer-generated invoice. <?= e(setting('academy_website', '')) ?></p>
  </div>
</div>

<div class="split wide no-print">
  <div class="card">
    <div class="card-h"><h2>Payment history</h2></div>
    <?php if (!$payments): ?>
      <div class="empty small">No payments recorded.</div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="stack">
        <thead><tr><th>Date</th><th class="num">Amount</th><th>Mode</th><th>Reference</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($payments as $p): ?>
          <tr>
            <td data-l="Date"><?= dmyt($p['paid_at']) ?><br>
              <span class="small muted"><?= $p['source'] === 'self' ? 'By rider' : e($p['staff'] ?: '—') ?></span></td>
            <td data-l="Amount" class="num"><b><?= money($p['amount']) ?></b></td>
            <td data-l="Mode"><span class="pill"><?= e(strtoupper($p['mode'])) ?></span></td>
            <td data-l="Reference"><?= e($p['reference'] ?: '—') ?></td>
            <td data-l="Status"><?= status_badge($p['status']) ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div>
    <?php if ($balance > 0 && $inv['status'] !== 'cancelled'): ?>
    <div class="card">
      <div class="card-h"><h3>Record a payment</h3></div>
      <form method="post"><div class="card-b">
        <?= csrf_field() ?><input type="hidden" name="action" value="pay">
        <div class="field"><label>Amount</label>
          <input type="number" name="amount" step="0.01" min="0.01" value="<?= e(number_format($balance, 2, '.', '')) ?>" required></div>
        <div class="field"><label>Mode</label>
          <select name="mode">
            <option value="cash">Cash</option><option value="upi">UPI</option>
            <option value="card">Card</option><option value="bank">Bank transfer</option>
            <option value="other">Other</option>
          </select></div>
        <div class="field"><label>Reference / UTR</label><input type="text" name="reference"></div>
        <div class="field"><label>Date</label><input type="date" name="paid_at" value="<?= today() ?>"></div>
      </div>
      <div class="card-f"><button class="btn btn-green btn-block">Record payment</button></div></form>
    </div>
    <?php endif; ?>

    <?php if (can('payments.verify') && $inv['status'] !== 'cancelled'): ?>
    <div class="card"><div class="card-b">
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <button class="btn btn-ghost btn-block"
                data-confirm="Cancel this invoice? Payments already recorded stay in the ledger.">Cancel invoice</button>
      </form>
    </div></div>
    <?php endif; ?>
  </div>
</div>
<?php layout_footer(); ?>

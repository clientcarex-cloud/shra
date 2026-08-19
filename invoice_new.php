<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('billing');

$pre    = gint('customer_id') ? find_customer(gint('customer_id')) : null;
$preSub = gint('subscription_id') ? one('SELECT * FROM subscriptions WHERE id=?', [gint('subscription_id')]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cid = pint('customer_id');
    try {
        if (!find_customer($cid)) throw new RuntimeException('Choose a rider for this invoice.');

        $items = [];
        foreach ((array) post('item_desc', []) as $i => $desc) {
            $items[] = [
                'plan_id'     => ((array) post('item_plan', []))[$i] ?: null,
                'description' => trim((string)$desc),
                'qty'         => (float) (((array) post('item_qty', []))[$i]  ?? 0),
                'rate'        => (float) (((array) post('item_rate', []))[$i] ?? 0),
            ];
        }

        db()->beginTransaction();
        $invId = create_invoice($cid, $items, [
            'subscription_id' => pint('subscription_id') ?: null,
            'discount'        => pdec('discount'),
            'tax_pct'         => pdec('tax_pct'),
            'issue_date'      => pstr('issue_date', today()),
            'due_date'        => pstr('due_date') ?: null,
            'notes'           => pstr('notes'),
        ]);
        $paid = pdec('paid_now');
        if ($paid > 0) add_payment($invId, $paid, pstr('pay_mode', 'cash'), ['reference' => pstr('pay_ref')]);
        db()->commit();

        log_activity('invoice', $invId, 'created');
        flash('Invoice created.');
        redirect('invoice_view.php?id=' . $invId);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash(e($e->getMessage()), 'error');
        redirect('invoice_new.php' . ($cid ? '?customer_id=' . $cid : ''));
    }
}

$plans  = plan_options($pre['category'] ?? null);
$taxPct = (float) setting('tax_pct', 0);

/** Renders one editable line-item row. */
function item_row(array $plans, array $row = [], string $idx = '0'): string
{
    ob_start(); ?>
  <tr>
    <td class="full" data-l="Item">
      <select class="i-plan" name="item_plan[]">
        <option value="">— pick a plan or type below —</option>
        <?php foreach ($plans as $p): ?>
          <option value="<?= (int)$p['id'] ?>" data-rate="<?= e($p['amount']) ?>" data-name="<?= e($p['name']) ?>"
            <?= (int)($row['plan_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
            <?= e($p['name']) ?> — <?= money($p['amount']) ?></option>
        <?php endforeach; ?>
      </select>
      <input class="i-desc" type="text" name="item_desc[]" placeholder="Description"
             value="<?= e($row['description'] ?? '') ?>" style="margin-top:.4rem">
    </td>
    <td data-l="Qty"><input class="i-qty" type="number" name="item_qty[]" step="0.5" min="0"
             value="<?= e($row['qty'] ?? '1') ?>"></td>
    <td data-l="Rate"><input class="i-rate" type="number" name="item_rate[]" step="0.01" min="0"
             value="<?= e($row['rate'] ?? '0') ?>"></td>
    <td data-l="Amount" class="num"><span class="i-amt">0.00</span></td>
    <td data-l=""><button type="button" class="btn btn-s btn-ghost i-del" aria-label="Remove">&times;</button></td>
  </tr>
    <?php return ob_get_clean();
}

$firstRow = [];
if ($preSub) $firstRow = ['plan_id' => $preSub['plan_id'], 'description' => $preSub['plan_name'], 'qty' => 1, 'rate' => $preSub['price']];

layout_header('New invoice');
?>
<div class="desk-bar page-h"><h1>New invoice</h1></div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="subscription_id" value="<?= (int)($preSub['id'] ?? 0) ?>">

  <div class="card">
    <div class="card-h"><h2>Bill to</h2></div>
    <div class="card-b">
      <?php if ($pre): ?>
        <input type="hidden" name="customer_id" value="<?= (int)$pre['id'] ?>">
        <div class="list-item" style="padding-left:0">
          <span class="avatar-lg"><?= e(initials(customer_name($pre))) ?></span>
          <div class="g"><b><?= e(customer_name($pre)) ?></b>
            <span><?= e($pre['code']) ?> &middot; <?= e($pre['phone']) ?></span></div>
          <a class="btn btn-s btn-ghost" href="invoice_new.php">Change</a>
        </div>
      <?php else: ?>
        <div class="field"><label>Rider <span class="req">*</span></label>
          <input type="text" data-cust-search="inv-cust" data-submit-on-pick
                 placeholder="Search name or mobile…" autocomplete="off">
          <input type="hidden" name="customer_id" id="inv-cust" required>
          <div class="help">New walk-in? Use <a href="guest_ride.php">Guest ride</a> to register and bill in one step.</div></div>
      <?php endif; ?>
      <div class="grid-2">
        <div class="field"><label>Invoice date</label>
          <input type="date" name="issue_date" value="<?= today() ?>"></div>
        <div class="field"><label>Due date</label>
          <input type="date" name="due_date" value="<?= today() ?>"></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Items</h2>
      <button type="button" class="btn btn-s btn-ghost" id="add-item"><?= icon('plus', 16) ?>  Add line</button></div>
    <div class="tbl-wrap"><table id="items" class="stack">
      <thead><tr><th>Item</th><th style="width:90px">Qty</th><th style="width:120px">Rate</th>
        <th style="width:110px" class="num">Amount</th><th style="width:44px"></th></tr></thead>
      <tbody><?= item_row($plans, $firstRow) ?></tbody>
    </table></div>
    <div class="card-b">
      <div class="grid-2">
        <div class="field"><label>Discount (<?= APP_CURRENCY ?>)</label>
          <input type="number" id="f-discount" name="discount" step="0.01" min="0" value="0"></div>
        <div class="field"><label><?= e(setting('tax_label', 'GST')) ?> %</label>
          <input type="number" id="f-taxpct" name="tax_pct" step="0.01" min="0" value="<?= e($taxPct) ?>"></div>
      </div>
      <dl class="kv" style="max-width:320px;margin-left:auto">
        <dt>Subtotal</dt><dd><?= APP_CURRENCY ?> <span id="t-sub">0.00</span></dd>
        <dt><?= e(setting('tax_label', 'GST')) ?></dt><dd><?= APP_CURRENCY ?> <span id="t-tax">0.00</span></dd>
        <dt><b>Total</b></dt><dd style="font-size:1.15rem"><b><?= APP_CURRENCY ?> <span id="t-total">0.00</span></b></dd>
      </dl>
      <div class="field mt"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Collect payment</h2></div>
    <div class="card-b">
      <div class="grid-2">
        <div class="field"><label>Amount received</label>
          <input type="number" name="paid_now" step="0.01" min="0" value="0">
          <div class="help">Leave 0 to send the bill with a scan-to-pay QR code.</div></div>
        <div class="field"><label>Mode</label>
          <select name="pay_mode">
            <option value="cash">Cash</option><option value="upi">UPI</option>
            <option value="card">Card</option><option value="bank">Bank transfer</option>
          </select></div>
      </div>
      <div class="field"><label>Reference / UTR</label><input type="text" name="pay_ref"></div>
    </div>
    <div class="card-f"><button class="btn btn-red btn-block" type="submit">Create invoice</button></div>
  </div>
</form>

<template id="item-tpl"><?= item_row($plans) ?></template>
<?php layout_footer(); ?>

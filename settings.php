<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('settings');
require_once __DIR__ . '/inc/qrcode.php';

$keys = ['academy_name', 'academy_short', 'academy_address', 'academy_phone', 'academy_email',
         'academy_website', 'academy_instagram', 'invoice_prefix', 'tax_pct', 'tax_label',
         'upi_id', 'upi_payee', 'self_billing', 'terms', 'site_url'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($keys as $k) {
        if ($k === 'self_billing') { setting_set($k, post('self_billing') ? '1' : '0'); continue; }
        if (array_key_exists($k, $_POST)) setting_set($k, trim((string)$_POST[$k]));
    }
    flash('Settings saved.');
    redirect('settings.php');
}

$upiSample = upi_uri(1000, 'SAMPLE');
layout_header('Settings');
?>
<div class="desk-bar page-h"><h1>Settings</h1></div>

<form method="post">
  <?= csrf_field() ?>
  <div class="split wide">
    <div>
      <div class="card">
        <div class="card-h"><h2>Academy details</h2></div>
        <div class="card-b">
          <div class="field"><label>Academy name</label>
            <input type="text" name="academy_name" value="<?= e(setting('academy_name')) ?>"></div>
          <div class="field"><label>Short name</label>
            <input type="text" name="academy_short" value="<?= e(setting('academy_short')) ?>">
            <div class="help">Shown in the sidebar and browser tab.</div></div>
          <div class="field"><label>Address</label>
            <textarea name="academy_address" rows="2"><?= e(setting('academy_address')) ?></textarea></div>
          <div class="grid-2">
            <div class="field"><label>Phone</label>
              <input type="text" name="academy_phone" value="<?= e(setting('academy_phone')) ?>"></div>
            <div class="field"><label>Email</label>
              <input type="email" name="academy_email" value="<?= e(setting('academy_email')) ?>"></div>
          </div>
          <div class="grid-2">
            <div class="field"><label>Website</label>
              <input type="text" name="academy_website" value="<?= e(setting('academy_website')) ?>"></div>
            <div class="field"><label>Instagram handle</label>
              <input type="text" name="academy_instagram" value="<?= e(setting('academy_instagram')) ?>"></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-h"><h2>Billing</h2></div>
        <div class="card-b">
          <div class="grid-2">
            <div class="field"><label>Invoice prefix</label>
              <input type="text" name="invoice_prefix" value="<?= e(setting('invoice_prefix')) ?>">
              <div class="help">Numbers look like <code><?= e(setting('invoice_prefix', 'SHRA')) ?>/<?= fy_label(today()) ?>/0001</code>.</div></div>
            <div class="field"><label>Tax label</label>
              <input type="text" name="tax_label" value="<?= e(setting('tax_label')) ?>"></div>
          </div>
          <div class="field"><label>Default tax %</label>
            <input type="number" name="tax_pct" step="0.01" min="0" value="<?= e(setting('tax_pct')) ?>">
            <div class="help">Set 0 if you do not charge GST on riding fees.</div></div>
          <div class="field"><label>Terms printed on invoices</label>
            <textarea name="terms" rows="5"><?= e(setting('terms')) ?></textarea></div>
        </div>
      </div>

      <div class="card">
        <div class="card-h"><h2>Self service &amp; UPI</h2></div>
        <div class="card-b">
          <label class="check"><input type="checkbox" name="self_billing" value="1"
            <?= setting('self_billing', '1') === '1' ? 'checked' : '' ?>>
            <span>Let riders bill themselves by scanning the counter QR</span></label>
          <div class="field"><label>UPI ID (VPA)</label>
            <input type="text" name="upi_id" placeholder="academy@okhdfcbank" value="<?= e(setting('upi_id')) ?>">
            <div class="help">Needed for the scan-to-pay QR on invoices and the rider portal.</div></div>
          <div class="field"><label>UPI payee name</label>
            <input type="text" name="upi_payee" value="<?= e(setting('upi_payee')) ?>"></div>
          <div class="field"><label>Public site URL</label>
            <input type="text" name="site_url" placeholder="https://app.stallionhorseriding.com" value="<?= e(setting('site_url')) ?>">
            <div class="help">Used when building QR links. Leave blank to detect it automatically
              (currently <code><?= e(rtrim(base_url(), '/')) ?></code>).</div></div>
        </div>
        <div class="card-f"><button class="btn btn-red">Save settings</button></div>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-h"><h3>UPI QR preview</h3></div>
        <div class="card-b center">
          <?php if ($upiSample): ?>
            <div class="qr-box"><?= QRCode::svg($upiSample, 180, 'M') ?>
              <div class="cap">Sample for <?= money(1000) ?></div></div>
            <p class="small muted mt">Scan with a UPI app to confirm the payee name is correct
              before printing posters.</p>
          <?php else: ?>
            <div class="empty small">Add a UPI ID to preview the payment QR.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-h"><h3>Branding</h3></div>
        <div class="card-b center">
          <div style="width:120px;margin:0 auto;color:var(--brown-800)"><?= logo_svg() ?></div>
          <p class="small muted mt">Replace <code>assets/img/logo-mark.svg</code> and
            <code>assets/img/favicon.svg</code> on the server with your own artwork to change this
            everywhere — sidebar, invoices, posters and the rider portal.</p>
        </div>
      </div>

      <div class="card">
        <div class="card-h"><h3>System</h3></div>
        <div class="card-b"><dl class="kv">
          <dt>App version</dt><dd><?= APP_VERSION ?></dd>
          <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
          <dt>Database</dt><dd><?= e(DB_NAME) ?></dd>
          <dt>Timezone</dt><dd><?= e(APP_TIMEZONE) ?></dd>
          <dt>Installer</dt><dd><?= is_file(APP_ROOT . '/install.php')
                ? '<span class="badge badge-danger">Still present</span>' : '<span class="badge badge-ok">Removed</span>' ?></dd>
        </dl>
        <?php if (is_file(APP_ROOT . '/install.php')): ?>
          <div class="flash flash-warn mt"><span>Delete <code>install.php</code> from the server
            now that setup is complete.</span></div>
        <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</form>
<?php layout_footer(); ?>

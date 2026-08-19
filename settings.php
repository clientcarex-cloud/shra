<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('settings');
require_once __DIR__ . '/inc/qrcode.php';

$keys = ['academy_name', 'academy_short', 'academy_address', 'academy_phone', 'academy_email',
         'academy_website', 'academy_instagram', 'invoice_prefix', 'tax_pct', 'tax_label',
         'upi_id', 'upi_payee', 'self_billing', 'terms', 'site_url', 'clean_urls'];

/** Save an uploaded academy logo to assets/img/logo-custom.<ext>. */
function handle_logo_upload(): void
{
    if (empty($_FILES['logo']['name'])) return;
    $f = $_FILES['logo'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        flash('Upload failed (error code ' . (int)$f['error'] . '). The file may be larger than the server allows.', 'error');
        return;
    }
    if ($f['size'] > 3 * 1024 * 1024) {
        flash('Please use a logo under 3 MB.', 'error');
        return;
    }

    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $ext  = $ext === 'jpeg' ? 'jpg' : $ext;
    $dir  = APP_ROOT . '/assets/img';

    if ($ext === 'svg') {
        $svg = (string) file_get_contents($f['tmp_name']);
        // Refuse anything scriptable — an SVG is markup, not just a picture.
        if (!preg_match('/<svg[\s>]/i', $svg)
            || preg_match('/<script|<foreignObject|javascript:|\son\w+\s*=/i', $svg)) {
            flash('That SVG contains scripting and was rejected. Export a plain SVG, or upload a PNG.', 'error');
            return;
        }
        $target = $dir . '/logo-custom.svg';
    } elseif (in_array($ext, ['png', 'jpg', 'webp'], true)) {
        $info = @getimagesize($f['tmp_name']);
        $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($allowed[$info[2]])) {
            flash('That file is not a readable PNG, JPG or WEBP image.', 'error');
            return;
        }
        if (!is_writable($dir)) {
            flash('Cannot write to assets/img — set that folder writable (chmod 755) and try again.', 'error');
            return;
        }

        // Process into a scratch file first, so a failure leaves the old logo intact.
        $res = normalize_logo($f['tmp_name'], $info[2], $dir);
        if ($res['ok']) {
            foreach (['svg', 'png', 'webp', 'jpg'] as $old) @unlink($dir . '/logo-custom.' . $old);
            if (!@rename($res['tmp'], $dir . '/logo-custom.png')) {
                @unlink($res['tmp']);
                flash('Could not save the processed logo.', 'error');
                return;
            }
            @chmod($dir . '/logo-custom.png', 0644);
            flash(sprintf('Logo updated — resized from %s to %d×%d px (%s). It now appears on every screen, invoice and poster.',
                  e($res['from']), $res['w'], $res['h'], size_label($res['bytes'])));
            return;
        }

        // No GD on this host: keep the original file rather than refusing the upload.
        $target = $dir . '/logo-custom.' . $allowed[$info[2]];
        foreach (['svg', 'png', 'webp', 'jpg'] as $old) @unlink($dir . '/logo-custom.' . $old);
        if (!move_uploaded_file($tmp, $target)) {
            flash('Could not save the uploaded file.', 'error');
            return;
        }
        @chmod($target, 0644);
        flash('Logo updated. (This server has no GD image support, so the file was stored at its '
            . 'original ' . $info[0] . '×' . $info[1] . ' px — it will still display correctly.)', 'warn');
        return;
    } else {
        flash('Use a PNG, JPG, WEBP or SVG file.', 'error');
        return;
    }

    if (!is_writable($dir)) {
        flash('Cannot write to assets/img — set that folder writable (chmod 755) and try again.', 'error');
        return;
    }
    foreach (['svg', 'png', 'webp', 'jpg'] as $old) @unlink($dir . '/logo-custom.' . $old);

    if (!move_uploaded_file($f['tmp_name'], $target)) {
        flash('Could not save the uploaded file.', 'error');
        return;
    }
    @chmod($target, 0644);
    flash('Logo updated — it now appears on every screen, invoice and poster.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (pstr('action') === 'remove_logo') {
        foreach (['svg', 'png', 'webp', 'jpg'] as $old) @unlink(APP_ROOT . '/assets/img/logo-custom.' . $old);
        flash('Custom logo removed — the built-in mark is back.', 'info');
        redirect('settings.php');
    }

    handle_logo_upload();

    foreach ($keys as $k) {
        if ($k === 'self_billing') { setting_set($k, post('self_billing') ? '1' : '0'); continue; }
        if ($k === 'clean_urls')   { setting_set($k, post('clean_urls')   ? '1' : '0'); continue; }
        if (array_key_exists($k, $_POST)) setting_set($k, trim((string)$_POST[$k]));
    }
    flash('Settings saved.');
    redirect('settings.php');
}

$upiSample = upi_uri(1000, 'SAMPLE');
layout_header('Settings');
?>
<div class="desk-bar page-h"><h1>Settings</h1></div>

<form method="post" enctype="multipart/form-data">
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
          <label class="check"><input type="checkbox" name="clean_urls" value="1"
            <?= setting('clean_urls', '1') === '1' ? 'checked' : '' ?>>
            <span>Tidy web addresses &mdash; show <code>/customers</code> instead of <code>/customers.php</code></span></label>
          <div class="help" style="margin:-.3rem 0 .9rem">
            Needs Apache <code>mod_rewrite</code> with <code>AllowOverride All</code> (or the Nginx rules
            in the README). If links start 404-ing, switch this off &mdash; you can always reach this page
            at <code>settings.php</code>.
            <?php if (setting('clean_urls', '1') === '1'):
                    $probe = probe_clean_urls(); ?>
              <div style="margin-top:.4rem">
                <?php if ($probe === true): ?>
                  <span class="badge badge-ok">Working</span> this server resolves <code><?= e(rtrim(base_url(), '/')) ?>/login</code>.
                <?php elseif ($probe === false): ?>
                  <span class="badge badge-danger">Not working</span> this server returns 404 for
                  <code><?= e(rtrim(base_url(), '/')) ?>/login</code> &mdash; turn this off, or enable
                  <code>mod_rewrite</code>.
                <?php else: ?>
                  <span class="badge badge-muted">Not checked</span> the self-test could not run on this host.
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

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
        <div class="card-h"><h3>Academy logo</h3></div>
        <div class="card-b center">
          <div style="width:130px;margin:0 auto;color:var(--brown-800)"><?= logo_svg() ?></div>
          <p class="small muted mt">
            <?= custom_logo() ? 'Your uploaded logo is in use.' : 'Using the built-in mark.' ?>
            It appears on every screen, invoice, receipt, QR poster and the rider portal.</p>
          <div class="field mt" style="text-align:left">
            <label>Upload your logo</label>
            <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg,image/*">
            <div class="help">PNG, JPG, WEBP or SVG, up to 3 MB. A square image with a
              transparent background looks best in the sidebar.</div>
          </div>
          <?php if (custom_logo()): ?>
            <button class="btn btn-s btn-ghost" name="action" value="remove_logo" type="submit"
                    formnovalidate data-confirm="Remove the uploaded logo and go back to the built-in mark?">
              Remove uploaded logo</button>
          <?php endif; ?>
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

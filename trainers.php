<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('trainers');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id  = pint('id');
    $act = pstr('action');

    if ($act === 'save') {
        $data = [
            'name'           => pstr('name'),
            'phone'          => pstr('phone'),
            'email'          => pstr('email'),
            'specialization' => pstr('specialization'),
            'experience_yrs' => pdec('experience_yrs'),
            'joining_date'   => pstr('joining_date') ?: null,
            'session_rate'   => pdec('session_rate'),
            'address'        => pstr('address'),
            'notes'          => pstr('notes'),
            'status'         => pstr('status', 'active'),
        ];
        if ($data['name'] === '') { flash('Trainer name is required.', 'error'); redirect('trainers.php'); }

        if ($id) { update('trainers', $data, 'id=?', [$id]); flash('Trainer updated.'); }
        else {
            $data['created_at'] = now();
            $tid = insert('trainers', $data);
            // Optional staff login for the trainer
            if (pstr('make_login') === '1' && $data['email'] && strlen((string)post('login_pass', '')) >= 8) {
                $uid = insert('users', [
                    'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                    'password_hash' => password_hash((string)post('login_pass'), PASSWORD_DEFAULT),
                    'role' => 'trainer', 'status' => 'active', 'created_at' => now(),
                ]);
                update('trainers', ['user_id' => $uid], 'id=?', [$tid]);
            }
            flash('Trainer added.');
        }
    }
    if ($act === 'toggle') {
        $t = one('SELECT status FROM trainers WHERE id=?', [$id]);
        if ($t) update('trainers', ['status' => $t['status'] === 'active' ? 'inactive' : 'active'], 'id=?', [$id]);
        flash('Trainer status changed.', 'info');
    }
    redirect('trainers.php');
}

$edit = gint('edit') ? one('SELECT * FROM trainers WHERE id=?', [gint('edit')]) : null;
$rows = all('SELECT t.*,
    (SELECT COUNT(*) FROM ride_sessions r WHERE r.trainer_id=t.id AND r.status="present") AS total_rides,
    (SELECT COUNT(*) FROM ride_sessions r WHERE r.trainer_id=t.id AND r.status="present"
        AND r.ride_date>=DATE_FORMAT(CURDATE(),"%Y-%m-01")) AS month_rides,
    (SELECT COUNT(*) FROM subscriptions s WHERE s.trainer_id=t.id AND s.status="active") AS active_subs
    FROM trainers t ORDER BY t.status, t.name');

$v   = fn($k, $d = '') => e($edit[$k] ?? $d);
$sel = fn($k, $val, $d = '') => (($edit[$k] ?? $d) === $val ? 'selected' : '');

layout_header('Trainers');
?>
<div class="desk-bar page-h"><h1>Trainers</h1></div>

<div class="split wide">
  <div>
    <?php if (!$rows): ?>
      <div class="card"><div class="empty"><div class="big-icon"><?= icon('whistle', 44) ?></div>No trainers yet — add the first one.</div></div>
    <?php else: foreach ($rows as $t): ?>
      <div class="card" style="<?= $t['status'] === 'inactive' ? 'opacity:.6' : '' ?>">
        <div class="card-b">
          <div style="display:flex;gap:.8rem;align-items:flex-start">
            <span class="avatar-lg"><?= e(initials($t['name'])) ?></span>
            <div style="flex:1;min-width:0">
              <h3 style="margin:0"><?= e($t['name']) ?>
                <?php if ($t['status'] === 'inactive'): ?><span class="badge badge-muted">Inactive</span><?php endif; ?></h3>
              <div class="small muted"><?= e($t['specialization'] ?: 'Riding instructor') ?>
                <?php if ((float)$t['experience_yrs'] > 0): ?>
                  &middot; <?= rtrim(rtrim(number_format((float)$t['experience_yrs'], 1), '0'), '.') ?> yrs experience<?php endif; ?></div>
              <div class="small mt-s">
                <?php if ($t['phone']): ?><a href="tel:<?= e($t['phone']) ?>"><?= e($t['phone']) ?></a><?php endif; ?>
                <?php if ($t['email']): ?> &middot; <?= e($t['email']) ?><?php endif; ?>
              </div>
            </div>
          </div>
          <div class="stats mt" style="grid-template-columns:repeat(3,1fr);margin-bottom:0">
            <div class="stat"><span class="k">This month</span><span class="v"><?= (int)$t['month_rides'] ?></span>
              <span class="s">sessions</span></div>
            <div class="stat blue"><span class="k">All time</span><span class="v"><?= (int)$t['total_rides'] ?></span>
              <span class="s">sessions</span></div>
            <div class="stat green"><span class="k">Riders</span><span class="v"><?= (int)$t['active_subs'] ?></span>
              <span class="s">assigned</span></div>
          </div>
          <?php if ($t['notes']): ?><p class="small mt"><?= nl2br(e($t['notes'])) ?></p><?php endif; ?>
        </div>
        <div class="card-f btn-row">
          <a class="btn btn-s btn-ghost" href="?edit=<?= (int)$t['id'] ?>#form">Edit</a>
          <a class="btn btn-s btn-ghost" href="reports.php?tab=trainers&trainer=<?= (int)$t['id'] ?>">Sessions</a>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn btn-s btn-ghost"><?= $t['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card" id="form">
    <div class="card-h"><h3><?= $edit ? 'Edit trainer' : 'Add a trainer' ?></h3></div>
    <form method="post"><div class="card-b">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="field"><label>Name <span class="req">*</span></label>
        <input type="text" name="name" required value="<?= $v('name') ?>"></div>
      <div class="grid-2">
        <div class="field"><label>Mobile</label><input type="tel" name="phone" value="<?= $v('phone') ?>"></div>
        <div class="field"><label>Email</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
      </div>
      <div class="field"><label>Specialization</label>
        <input type="text" name="specialization" placeholder="Dressage, show jumping, beginners…"
               value="<?= $v('specialization') ?>"></div>
      <div class="grid-2">
        <div class="field"><label>Experience (years)</label>
          <input type="number" name="experience_yrs" step="0.5" min="0" value="<?= $v('experience_yrs', '0') ?>"></div>
        <div class="field"><label>Joining date</label>
          <input type="date" name="joining_date" value="<?= $v('joining_date') ?>"></div>
      </div>
      <div class="field"><label>Rate per session</label>
        <input type="number" name="session_rate" step="0.01" min="0" value="<?= $v('session_rate', '0') ?>">
        <div class="help">Used in the trainer payout report.</div></div>
      <div class="field"><label>Address</label><input type="text" name="address" value="<?= $v('address') ?>"></div>
      <div class="field"><label>Notes</label><textarea name="notes" rows="2"><?= $v('notes') ?></textarea></div>
      <div class="field"><label>Status</label>
        <select name="status">
          <option value="active"   <?= $sel('status', 'active', 'active') ?>>Active</option>
          <option value="inactive" <?= $sel('status', 'inactive') ?>>Inactive</option>
        </select></div>

      <?php if (!$edit && can('users')): ?>
        <hr>
        <label class="check"><input type="checkbox" name="make_login" value="1" id="mk-login">
          <span>Also create an app login for this trainer</span></label>
        <div class="field" id="pass-box" style="display:none"><label>Password (min 8 characters)</label>
          <input type="password" name="login_pass" minlength="8">
          <div class="help">Trainers can mark attendance and view rider profiles.</div></div>
      <?php endif; ?>
    </div>
    <div class="card-f btn-row">
      <button class="btn btn-red"><?= $edit ? 'Save trainer' : 'Add trainer' ?></button>
      <?php if ($edit): ?><a class="btn btn-ghost" href="trainers.php">Cancel</a><?php endif; ?>
    </div></form>
  </div>
</div>
<script>
(function () {
  var c = document.getElementById('mk-login'), b = document.getElementById('pass-box');
  if (c) c.addEventListener('change', function () { b.style.display = c.checked ? '' : 'none'; });
})();
</script>
<?php layout_footer(); ?>

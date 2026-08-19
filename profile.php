<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$u = current_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (pstr('action') === 'profile') {
        update('users', ['name' => pstr('name'), 'phone' => pstr('phone')], 'id=?', [$u['id']]);
        flash('Profile updated.');
    }
    if (pstr('action') === 'password') {
        $cur = (string) post('current', '');
        $new = (string) post('new', '');
        if (!password_verify($cur, $u['password_hash']))      flash('Current password is incorrect.', 'error');
        elseif (strlen($new) < 8)                             flash('New password must be at least 8 characters.', 'error');
        elseif ($new !== (string) post('confirm', ''))        flash('New passwords do not match.', 'error');
        else {
            update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id=?', [$u['id']]);
            flash('Password changed.');
        }
    }
    redirect('profile.php');
}

layout_header('My profile');
?>
<div class="desk-bar page-h"><h1>My profile</h1></div>
<div class="split wide">
  <div class="card">
    <div class="card-h"><h2>Details</h2></div>
    <form method="post"><div class="card-b">
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <div class="field"><label>Name</label><input type="text" name="name" value="<?= e($u['name']) ?>" required></div>
      <div class="field"><label>Email</label><input type="email" value="<?= e($u['email']) ?>" disabled></div>
      <div class="field"><label>Mobile</label><input type="tel" name="phone" value="<?= e($u['phone']) ?>"></div>
      <div class="field"><label>Role</label><input type="text" value="<?= e(ROLES[$u['role']] ?? $u['role']) ?>" disabled></div>
    </div>
    <div class="card-f"><button class="btn btn-red">Save</button></div></form>
  </div>

  <div class="card">
    <div class="card-h"><h2>Change password</h2></div>
    <form method="post"><div class="card-b">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="field"><label>Current password</label>
        <input type="password" name="current" required autocomplete="current-password"></div>
      <div class="field"><label>New password</label>
        <input type="password" name="new" minlength="8" required autocomplete="new-password"></div>
      <div class="field"><label>Confirm new password</label>
        <input type="password" name="confirm" minlength="8" required autocomplete="new-password"></div>
    </div>
    <div class="card-f"><button class="btn btn-red">Update password</button></div></form>
  </div>
</div>
<div class="card"><div class="card-b center">
  <a class="btn btn-ghost" href="logout.php">Sign out</a>
</div></div>
<?php layout_footer(); ?>

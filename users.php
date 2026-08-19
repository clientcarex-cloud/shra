<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id  = pint('id');
    $act = pstr('action');
    $me  = (int) current_user()['id'];

    if ($act === 'save') {
        $data = [
            'name'  => pstr('name'),
            'email' => pstr('email'),
            'phone' => pstr('phone'),
            'role'  => pstr('role', 'staff'),
            'status'=> pstr('status', 'active'),
        ];
        $username = pstr('username');
        $data['username'] = $username !== '' ? $username : null;

        if ($data['name'] === '' || $data['email'] === '') {
            flash('Name and email are required.', 'error');
        } elseif (!array_key_exists($data['role'], ROLES)) {
            flash('Invalid role.', 'error');
        } else {
            $clash = one('SELECT id FROM users WHERE (email=? OR (username IS NOT NULL AND username=?)) AND id<>?',
                         [$data['email'], $data['username'], $id]);
            if ($clash) {
                flash('Another user already uses that email or username.', 'error');
            } elseif ($id) {
                if ($id === $me && $data['role'] !== 'admin') {
                    flash('You cannot remove your own administrator role.', 'error');
                } else {
                    $pw = (string) post('password', '');
                    if ($pw !== '') {
                        if (strlen($pw) < 8) { flash('Password must be at least 8 characters.', 'error'); redirect('users.php'); }
                        $data['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
                    }
                    update('users', $data, 'id=?', [$id]);
                    flash('User updated.');
                }
            } else {
                $pw = (string) post('password', '');
                if (strlen($pw) < 8) {
                    flash('Password must be at least 8 characters.', 'error');
                } else {
                    $data['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
                    $data['created_at'] = now();
                    insert('users', $data);
                    flash('User created.');
                }
            }
        }
    }
    if ($act === 'toggle' && $id !== $me) {
        $u = one('SELECT status FROM users WHERE id=?', [$id]);
        if ($u) update('users', ['status' => $u['status'] === 'active' ? 'disabled' : 'active'], 'id=?', [$id]);
        flash('User status changed.', 'info');
    }
    redirect('users.php');
}

$edit = gint('edit') ? one('SELECT * FROM users WHERE id=?', [gint('edit')]) : null;
$rows = all('SELECT * FROM users ORDER BY FIELD(role,"admin","manager","staff","trainer"), name');
$v   = fn($k, $d = '') => e($edit[$k] ?? $d);
$sel = fn($k, $val, $d = '') => (($edit[$k] ?? $d) === $val ? 'selected' : '');

layout_header('Staff logins');
?>
<div class="desk-bar page-h"><h1>Staff logins</h1></div>

<div class="split wide">
  <div class="card">
    <div class="card-h"><h2><?= count($rows) ?> user<?= count($rows) === 1 ? '' : 's' ?></h2></div>
    <div class="tbl-wrap"><table class="stack">
      <thead><tr><th>Name</th><th>Login</th><th>Role</th><th>Last seen</th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $u): ?>
        <tr style="<?= $u['status'] === 'disabled' ? 'opacity:.55' : '' ?>">
          <td data-l="Name"><b><?= e($u['name']) ?></b>
            <?php if ($u['status'] === 'disabled'): ?> <span class="badge badge-muted">Disabled</span><?php endif; ?></td>
          <td data-l="Login"><?= e($u['email']) ?>
            <?php if ($u['username']): ?><br><span class="small muted">@<?= e($u['username']) ?></span><?php endif; ?></td>
          <td data-l="Role"><span class="pill"><?= e(ROLES[$u['role']] ?? $u['role']) ?></span></td>
          <td data-l="Last seen"><?= $u['last_login'] ? dmyt($u['last_login']) : '<span class="muted">never</span>' ?></td>
          <td data-l="" class="num nowrap">
            <a class="btn btn-s btn-ghost" href="?edit=<?= (int)$u['id'] ?>#form">Edit</a>
            <?php if ((int)$u['id'] !== (int)current_user()['id']): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-s btn-ghost"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div>

  <div class="card" id="form">
    <div class="card-h"><h3><?= $edit ? 'Edit user' : 'Add a user' ?></h3></div>
    <form method="post"><div class="card-b">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="field"><label>Full name <span class="req">*</span></label>
        <input type="text" name="name" required value="<?= $v('name') ?>"></div>
      <div class="field"><label>Email <span class="req">*</span></label>
        <input type="email" name="email" required value="<?= $v('email') ?>"></div>
      <div class="grid-2">
        <div class="field"><label>Mobile</label><input type="tel" name="phone" value="<?= $v('phone') ?>"></div>
        <div class="field"><label>Username</label><input type="text" name="username" value="<?= $v('username') ?>"></div>
      </div>
      <div class="field"><label>Role</label>
        <select name="role">
          <?php foreach (ROLES as $k => $lb): ?>
            <option value="<?= $k ?>" <?= $sel('role', $k, 'staff') ?>><?= e($lb) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="help"><b>Manager</b> sees everything except staff logins and settings.
          <b>Front desk</b> handles riders, rides, billing and leads. <b>Trainer</b> marks attendance only.</div></div>
      <div class="field"><label>Password <?= $edit ? '' : '<span class="req">*</span>' ?></label>
        <input type="password" name="password" minlength="8" <?= $edit ? '' : 'required' ?>>
        <?php if ($edit): ?><div class="help">Leave blank to keep the current password.</div><?php endif; ?></div>
      <div class="field"><label>Status</label>
        <select name="status">
          <option value="active"   <?= $sel('status', 'active', 'active') ?>>Active</option>
          <option value="disabled" <?= $sel('status', 'disabled') ?>>Disabled</option>
        </select></div>
    </div>
    <div class="card-f btn-row">
      <button class="btn btn-red"><?= $edit ? 'Save user' : 'Create user' ?></button>
      <?php if ($edit): ?><a class="btn btn-ghost" href="users.php">Cancel</a><?php endif; ?>
    </div></form>
  </div>
</div>
<?php layout_footer(); ?>

<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('leads');

$id = gint('id');
$l  = $id ? one('SELECT * FROM leads WHERE id=?', [$id]) : null;
if ($id && !$l) { flash('Lead not found.', 'error'); redirect('leads.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = [
        'name'          => pstr('name'),
        'phone'         => pstr('phone'),
        'email'         => pstr('email'),
        'city'          => pstr('city'),
        'source'        => pstr('source'),
        'interest'      => pstr('interest', 'unknown'),
        'plan_interest' => pstr('plan_interest'),
        'status'        => pstr('status', 'new'),
        'assigned_to'   => pint('assigned_to') ?: null,
        'next_followup' => pstr('next_followup') ?: null,
        'notes'         => pstr('notes'),
    ];
    if ($data['name'] === '' || $data['phone'] === '') {
        flash('Name and mobile number are required.', 'error');
    } else {
        if ($l) {
            update('leads', $data, 'id=?', [$id]);
            lead_note($id, 'updated', 'Details edited');
            flash('Lead updated.');
            redirect('lead_view.php?id=' . $id);
        } else {
            $data['created_by'] = current_user()['id'];
            $data['created_at'] = now();
            $new = insert('leads', $data);
            lead_note($new, 'created', 'Lead captured');
            flash('Lead added.');
            redirect('lead_view.php?id=' . $new);
        }
    }
    $l = array_merge($l ?? [], $data);
}

$staff = all('SELECT id,name FROM users WHERE status="active" ORDER BY name');
$v   = fn($k, $d = '') => e($l[$k] ?? $d);
$sel = fn($k, $val, $d = '') => (($l[$k] ?? $d) === $val ? 'selected' : '');

layout_header($id ? 'Edit lead' : 'New lead');
?>
<div class="desk-bar page-h"><h1><?= $id ? 'Edit lead' : 'New lead' ?></h1></div>
<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-b">
      <div class="field"><label>Name <span class="req">*</span></label>
        <input type="text" name="name" required value="<?= $v('name') ?>"></div>
      <div class="grid-2">
        <div class="field"><label>Mobile <span class="req">*</span></label>
          <input type="tel" name="phone" required value="<?= $v('phone') ?>"></div>
        <div class="field"><label>Email</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>City</label><input type="text" name="city" value="<?= $v('city') ?>"></div>
        <div class="field"><label>Source</label>
          <input type="text" name="source" list="src" value="<?= $v('source') ?>">
          <datalist id="src"><option>Walk-in</option><option>Instagram</option><option>Facebook</option>
            <option>Google</option><option>Referral</option><option>Website</option><option>Event</option>
            <option>Phone enquiry</option></datalist></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Riding for</label>
          <select name="interest">
            <option value="unknown" <?= $sel('interest', 'unknown', 'unknown') ?>>Not sure</option>
            <option value="child"   <?= $sel('interest', 'child') ?>>Child</option>
            <option value="adult"   <?= $sel('interest', 'adult') ?>>Adult</option>
            <option value="both"    <?= $sel('interest', 'both') ?>>Both</option>
          </select></div>
        <div class="field"><label>Plan of interest</label>
          <input type="text" name="plan_interest" placeholder="Monthly, guest ride…" value="<?= $v('plan_interest') ?>"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Stage</label>
          <select name="status">
            <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'follow_up' => 'Follow up',
                            'visit_scheduled' => 'Visit scheduled', 'converted' => 'Converted', 'lost' => 'Lost'] as $k => $lb): ?>
              <option value="<?= $k ?>" <?= $sel('status', $k, 'new') ?>><?= $lb ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Next follow-up</label>
          <input type="date" name="next_followup" value="<?= $v('next_followup') ?>"></div>
      </div>
      <div class="field"><label>Assign to</label>
        <select name="assigned_to">
          <option value="">— Unassigned —</option>
          <?php foreach ($staff as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)($l['assigned_to'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
              <?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>Notes</label><textarea name="notes" rows="3"><?= $v('notes') ?></textarea></div>
    </div>
    <div class="card-f btn-row">
      <button class="btn btn-red"><?= $id ? 'Save lead' : 'Add lead' ?></button>
      <a class="btn btn-ghost" href="<?= $id ? 'lead_view.php?id=' . $id : 'leads.php' ?>">Cancel</a>
    </div>
  </div>
</form>
<?php layout_footer(); ?>

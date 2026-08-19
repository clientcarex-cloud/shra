<?php
/** Add / edit a rider. Mirrors the printed SHRA membership form. */
require_once __DIR__ . '/inc/bootstrap.php';
require_can('customers.edit');

$id  = gint('id');
$c   = $id ? find_customer($id) : null;
if ($id && !$c) { flash('Rider not found.', 'error'); redirect('customers.php'); }

$leadId = gint('lead');
$lead   = $leadId ? one('SELECT * FROM leads WHERE id=?', [$leadId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = [
        'first_name'     => pstr('first_name'),
        'last_name'      => pstr('last_name'),
        'father_spouse'  => pstr('father_spouse'),
        'guardian_name'  => pstr('guardian_name'),
        'guardian_rel'   => pstr('guardian_rel'),
        'dob'            => pstr('dob') ?: null,
        'place_of_birth' => pstr('place_of_birth'),
        'gender'         => pstr('gender') ?: null,
        'riding_level'   => pstr('riding_level', 'beginner'),
        'marital_status' => pstr('marital_status') ?: null,
        'phone'          => pstr('phone'),
        'alt_phone'      => pstr('alt_phone'),
        'email'          => pstr('email'),
        'address'        => pstr('address'),
        'city'           => pstr('city'),
        'postcode'       => pstr('postcode'),
        'country'        => pstr('country', 'India'),
        'nationality'    => pstr('nationality', 'Indian'),
        'category'       => pstr('category', 'adult'),
        'medical_notes'  => pstr('medical_notes'),
        'notes'          => pstr('notes'),
        'source'         => pstr('source'),
        'status'         => pstr('status', 'active'),
    ];

    $err = [];
    if ($data['first_name'] === '') $err[] = 'First name is required.';
    if ($data['phone'] === '')      $err[] = 'Mobile number is required.';

    // Auto-classify child/adult from DOB when one is given
    if ($data['dob']) {
        $age = age_from($data['dob']);
        if ($age !== null) $data['category'] = $age < 18 ? 'child' : 'adult';
    }

    if ($err) {
        foreach ($err as $e) flash($e, 'error');
    } else {
        if ($c) {
            update('customers', $data, 'id=?', [$c['id']]);
            log_activity('customer', (int)$c['id'], 'updated');
            flash('Rider details updated.');
            redirect('customer_view.php?id=' . (int)$c['id']);
        } else {
            $data['code']       = next_customer_code();
            $data['portal_pin'] = (string) random_int(1000, 9999);
            $data['lead_id']    = $leadId ?: null;
            $data['created_by'] = current_user()['id'];
            $data['created_at'] = now();
            $newId = insert('customers', $data);
            log_activity('customer', $newId, 'created');

            if ($lead) {
                update('leads', ['status' => 'converted', 'customer_id' => $newId], 'id=?', [$lead['id']]);
                lead_note((int)$lead['id'], 'converted', 'Converted to rider ' . $data['code']);
            }
            flash('Rider registered. Portal PIN is <b>' . e($data['portal_pin']) . '</b>.');
            redirect('customer_view.php?id=' . $newId);
        }
    }
    $c = array_merge($c ?? [], $data);
}

// Prefill from a lead
if (!$c && $lead) {
    $parts = preg_split('/\s+/', $lead['name'], 2);
    $c = [
        'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '',
        'phone' => $lead['phone'], 'email' => $lead['email'], 'city' => $lead['city'],
        'source' => $lead['source'],
        'category' => in_array($lead['interest'], ['child', 'adult'], true) ? $lead['interest'] : 'adult',
    ];
}
$v = fn(string $k, $d = '') => e($c[$k] ?? $d);
$sel = fn(string $k, string $val, $d = '') => (($c[$k] ?? $d) === $val ? 'selected' : '');
$chk = fn(string $k, string $val, $d = '') => (($c[$k] ?? $d) === $val ? 'checked' : '');

layout_header($id ? 'Edit rider' : 'New rider');
?>
<div class="desk-bar page-h"><h1><?= $id ? 'Edit rider' : 'New rider registration' ?></h1></div>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-h"><h2>Personal information</h2></div>
    <div class="card-b">
      <div class="grid-2">
        <div class="field"><label>First name <span class="req">*</span></label>
          <input type="text" name="first_name" required value="<?= $v('first_name') ?>"></div>
        <div class="field"><label>Surname</label>
          <input type="text" name="last_name" value="<?= $v('last_name') ?>"></div>
      </div>
      <div class="field"><label>Rider's father / spouse name</label>
        <input type="text" name="father_spouse" value="<?= $v('father_spouse') ?>"></div>
      <div class="grid-2">
        <div class="field"><label>Guardian's name (if any)</label>
          <input type="text" name="guardian_name" value="<?= $v('guardian_name') ?>"></div>
        <div class="field"><label>Relationship</label>
          <input type="text" name="guardian_rel" placeholder="Mother, Father…" value="<?= $v('guardian_rel') ?>"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Date of birth</label>
          <input type="date" name="dob" value="<?= $v('dob') ?>">
          <div class="help">Sets child / adult fees automatically.</div></div>
        <div class="field"><label>Place of birth</label>
          <input type="text" name="place_of_birth" value="<?= $v('place_of_birth') ?>"></div>
      </div>
      <div class="field"><label>Riding level</label>
        <div class="radio-row">
          <?php foreach (['beginner' => 'Beginner', 'novice' => 'Novice', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'] as $k => $lb): ?>
            <label><input type="radio" name="riding_level" value="<?= $k ?>" <?= $chk('riding_level', $k, 'beginner') ?>><span><?= $lb ?></span></label>
          <?php endforeach; ?>
        </div></div>
      <div class="grid-2">
        <div class="field"><label>Gender</label>
          <select name="gender">
            <option value="">—</option>
            <option value="male"   <?= $sel('gender', 'male') ?>>Male</option>
            <option value="female" <?= $sel('gender', 'female') ?>>Female</option>
            <option value="other"  <?= $sel('gender', 'other') ?>>Other</option>
          </select></div>
        <div class="field"><label>Fee category</label>
          <select name="category">
            <option value="child" <?= $sel('category', 'child') ?>>Child (under 18)</option>
            <option value="adult" <?= $sel('category', 'adult', 'adult') ?>>Adult (over 18)</option>
          </select></div>
      </div>
      <div class="field"><label>Status</label>
        <select name="marital_status">
          <option value="">—</option>
          <?php foreach (['single' => 'Single', 'married' => 'Married', 'divorced' => 'Divorce', 'other' => 'Others'] as $k => $lb): ?>
            <option value="<?= $k ?>" <?= $sel('marital_status', $k) ?>><?= $lb ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Contact</h2></div>
    <div class="card-b">
      <div class="grid-2">
        <div class="field"><label>Mobile number <span class="req">*</span></label>
          <input type="tel" name="phone" required value="<?= $v('phone') ?>"></div>
        <div class="field"><label>Alternate mobile</label>
          <input type="tel" name="alt_phone" value="<?= $v('alt_phone') ?>"></div>
      </div>
      <div class="field"><label>Email address</label>
        <input type="email" name="email" value="<?= $v('email') ?>"></div>
      <div class="field"><label>Full address</label>
        <textarea name="address" rows="2"><?= $v('address') ?></textarea></div>
      <div class="grid-2">
        <div class="field"><label>City</label><input type="text" name="city" value="<?= $v('city') ?>"></div>
        <div class="field"><label>Postcode</label><input type="text" name="postcode" value="<?= $v('postcode') ?>"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Nationality</label>
          <input type="text" name="nationality" value="<?= $v('nationality', 'Indian') ?>"></div>
        <div class="field"><label>Country</label>
          <input type="text" name="country" value="<?= $v('country', 'India') ?>"></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Other details</h2></div>
    <div class="card-b">
      <div class="field"><label>Medical notes / allergies</label>
        <textarea name="medical_notes" rows="2" placeholder="Anything the trainer must know before a ride"><?= $v('medical_notes') ?></textarea></div>
      <div class="field"><label>Comments</label>
        <textarea name="notes" rows="2"><?= $v('notes') ?></textarea></div>
      <div class="grid-2">
        <div class="field"><label>How did they hear about us?</label>
          <input type="text" name="source" list="src" value="<?= $v('source') ?>">
          <datalist id="src"><option>Walk-in</option><option>Instagram</option><option>Google</option>
            <option>Referral</option><option>Website</option><option>Event</option></datalist></div>
        <div class="field"><label>Record status</label>
          <select name="status">
            <option value="active"   <?= $sel('status', 'active', 'active') ?>>Active</option>
            <option value="inactive" <?= $sel('status', 'inactive') ?>>Inactive</option>
          </select></div>
      </div>
    </div>
    <div class="card-f btn-row">
      <button class="btn btn-red" type="submit"><?= $id ? 'Save changes' : 'Register rider' ?></button>
      <a class="btn btn-ghost" href="<?= $id ? 'customer_view.php?id=' . $id : 'customers.php' ?>">Cancel</a>
    </div>
  </div>
</form>
<?php layout_footer(); ?>

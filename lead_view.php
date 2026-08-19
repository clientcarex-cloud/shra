<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_can('leads');

$id = gint('id');
$l  = one('SELECT l.*, u.name AS owner_name, c.code AS cust_code
           FROM leads l LEFT JOIN users u ON u.id=l.assigned_to
           LEFT JOIN customers c ON c.id=l.customer_id WHERE l.id=?', [$id]);
if (!$l) { flash('Lead not found.', 'error'); redirect('leads.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = pstr('action');

    if ($act === 'log') {
        $kind = pstr('kind', 'note');
        $note = pstr('note');
        $newStatus = pstr('status');
        $next = pstr('next_followup');

        $upd = [];
        // Blank means "keep the current stage" — never demote a converted lead by accident.
        if ($newStatus !== '' && $newStatus !== $l['status'] && $l['status'] !== 'converted') {
            $upd['status'] = $newStatus;
        }
        if ($next !== '') $upd['next_followup'] = $next;
        if ($newStatus === 'lost') $upd['lost_reason'] = $note;
        if ($upd) update('leads', $upd, 'id=?', [$id]);

        lead_note($id, $kind, $note !== '' ? $note : ucfirst(str_replace('_', ' ', $kind)));
        flash('Activity logged.');
    }
    redirect('lead_view.php?id=' . $id);
}

$acts = all('SELECT a.*, u.name AS staff FROM lead_activities a LEFT JOIN users u ON u.id=a.user_id
             WHERE a.lead_id=? ORDER BY a.id DESC', [$id]);
$wa = preg_replace('/\D+/', '', $l['phone']);

layout_header($l['name']);
?>
<div class="card">
  <div class="card-b">
    <div style="display:flex;gap:.9rem;align-items:flex-start">
      <span class="avatar-lg"><?= e(initials($l['name'])) ?></span>
      <div style="flex:1;min-width:0">
        <h1 style="margin:0;font-size:1.2rem"><?= e($l['name']) ?></h1>
        <div class="small muted"><?= e($l['phone']) ?><?= $l['city'] ? ' &middot; ' . e($l['city']) : '' ?>
          <?= $l['source'] ? ' &middot; via ' . e($l['source']) : '' ?></div>
        <div class="mt-s"><?= status_badge($l['status']) ?>
          <span class="pill">For <?= e($l['interest']) ?></span>
          <?php if ($l['plan_interest']): ?><span class="pill"><?= e($l['plan_interest']) ?></span><?php endif; ?></div>
      </div>
    </div>
    <div class="btn-row mt">
      <a class="btn btn-s" href="tel:<?= e($l['phone']) ?>"><?= icon('user', 16) ?> Call</a>
      <?php if ($wa): ?>
        <a class="btn btn-s btn-green" target="_blank" rel="noopener"
           href="https://wa.me/<?= e(strlen($wa) === 10 ? '91' . $wa : $wa) ?>?text=<?= rawurlencode('Hello ' . $l['name'] . ', greetings from ' . setting('academy_name', APP_NAME) . '!') ?>">WhatsApp</a>
      <?php endif; ?>
      <a class="btn btn-s btn-ghost" href="lead_edit.php?id=<?= $id ?>">Edit</a>
      <?php if ($l['status'] !== 'converted' && can('customers.edit')): ?>
        <a class="btn btn-s btn-red" href="customer_edit.php?lead=<?= $id ?>">Convert to rider</a>
      <?php elseif ($l['customer_id']): ?>
        <a class="btn btn-s btn-ghost" href="customer_view.php?id=<?= (int)$l['customer_id'] ?>">
          Open rider <?= e($l['cust_code']) ?></a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="split wide">
  <div class="card">
    <div class="card-h"><h2>Activity</h2><span class="pill"><?= count($acts) ?></span></div>
    <?php if (!$acts): ?><div class="empty small">Nothing logged yet.</div>
    <?php else: foreach ($acts as $a): ?>
      <div class="list-item">
        <div class="g">
          <b><?= e(ucfirst(str_replace('_', ' ', $a['kind']))) ?></b>
          <span style="white-space:normal"><?= nl2br(e($a['note'])) ?></span>
          <span class="small muted"><?= dmyt($a['created_at']) ?> &middot; <?= e($a['staff'] ?: 'System') ?></span>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-h"><h3>Log a follow-up</h3></div>
      <form method="post"><div class="card-b">
        <?= csrf_field() ?><input type="hidden" name="action" value="log">
        <div class="field"><label>What happened?</label>
          <select name="kind">
            <option value="call">Phone call</option><option value="whatsapp">WhatsApp</option>
            <option value="visit">Academy visit</option><option value="email">Email</option>
            <option value="note">Note</option>
          </select></div>
        <div class="field"><label>Notes</label><textarea name="note" rows="3" placeholder="What was discussed…"></textarea></div>
        <div class="field"><label>Move to stage</label>
          <select name="status" <?= $l['status'] === 'converted' ? 'disabled' : '' ?>>
            <option value="">— keep at <?= e(ucwords(str_replace('_', ' ', $l['status']))) ?> —</option>
            <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'follow_up' => 'Follow up',
                            'visit_scheduled' => 'Visit scheduled', 'lost' => 'Lost'] as $k => $lb):
                  if ($k === $l['status']) continue; ?>
              <option value="<?= $k ?>"><?= $lb ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($l['status'] === 'converted'): ?>
            <div class="help">This lead is already a registered rider, so the stage is locked.</div>
          <?php endif; ?></div>
        <div class="field"><label>Next follow-up date</label>
          <input type="date" name="next_followup" value="<?= e($l['next_followup'] ?: '') ?>"></div>
      </div>
      <div class="card-f"><button class="btn btn-red btn-block">Save activity</button></div></form>
    </div>

    <div class="card">
      <div class="card-h"><h3>Details</h3></div>
      <div class="card-b"><dl class="kv">
        <dt>Owner</dt><dd><?= e($l['owner_name'] ?: 'Unassigned') ?></dd>
        <dt>Email</dt><dd><?= e($l['email'] ?: '—') ?></dd>
        <dt>Created</dt><dd><?= dmy($l['created_at']) ?></dd>
        <dt>Last contact</dt><dd><?= $l['last_contact'] ? dmyt($l['last_contact']) : '—' ?></dd>
        <dt>Next follow-up</dt><dd><?= $l['next_followup'] ? dmy($l['next_followup']) : '—' ?></dd>
        <?php if ($l['lost_reason']): ?><dt>Lost reason</dt><dd><?= e($l['lost_reason']) ?></dd><?php endif; ?>
      </dl>
      <?php if ($l['notes']): ?><hr><p class="small"><?= nl2br(e($l['notes'])) ?></p><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php layout_footer(); ?>

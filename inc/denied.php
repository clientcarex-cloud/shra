<?php layout_header('Access denied'); ?>
<div class="card pad center">
  <div class="big-icon"><?= icon('lock', 44) ?></div>
  <h2>Access denied</h2>
  <p class="muted">Your role (<b><?= e(ROLES[role()] ?? role()) ?></b>) does not have permission to open this section.</p>
  <a class="btn" href="index.php">Back to dashboard</a>
</div>
<?php layout_footer(); ?>

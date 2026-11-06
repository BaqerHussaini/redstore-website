<?php
declare(strict_types=1);
$status = (int) ($status ?? 500);
$message = (string) ($message ?? 'Something went wrong.');
?>
<section class="admin-panel admin-panel--narrow">
  <p class="admin-eyebrow">Error <?= e((string) $status) ?></p>
  <h2><?= $status === 404 ? 'Page not found' : ($status === 403 ? 'Access denied' : 'Request failed') ?></h2>
  <p class="admin-muted"><?= e($message) ?></p>
  <p><a class="admin-button admin-button--primary" href="<?= e(admin_url('')) ?>">Back to dashboard</a></p>
</section>

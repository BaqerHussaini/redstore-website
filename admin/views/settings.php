<?php
declare(strict_types=1);
$account = $account ?? [];
$integration = $integration ?? [];
?>
<div class="admin-page-head">
  <div>
    <p class="admin-eyebrow">Account</p>
    <h2>Settings</h2>
    <p class="admin-muted">Administrator account and server integration status. Storefront branding, static pages, navigation and layout remain in source code by design.</p>
  </div>
</div>

<div class="admin-split">
  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Administrator</h3>
    </div>
    <dl class="admin-detail-list">
      <div><dt>Name</dt><dd><?= e($account['full_name'] ?: 'Not set') ?></dd></div>
      <div><dt>Email</dt><dd><?= e($account['email'] ?? '') ?></dd></div>
      <div><dt>Role</dt><dd><span class="admin-badge is-brand"><?= e($account['role'] ?? 'admin') ?></span></dd></div>
      <div><dt>Account status</dt><dd><span class="admin-badge <?= !empty($account['is_active']) ? 'is-success' : 'is-danger' ?>"><?= !empty($account['is_active']) ? 'Active' : 'Inactive' ?></span></dd></div>
      <div><dt>Created</dt><dd><?= e(admin_date($account['created_at'] ?? null)) ?></dd></div>
    </dl>

    <h4>Update profile</h4>
    <form class="admin-form" method="post" action="<?= e(admin_url('settings/profile')) ?>">
      <?= admin_csrf_field() ?>
      <div class="admin-field">
        <label for="full_name">Name</label>
        <input id="full_name" name="full_name" type="text" value="<?= e($account['full_name'] ?? '') ?>" maxlength="150" autocomplete="name">
      </div>
      <div class="admin-field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?= e($account['email'] ?? '') ?>" maxlength="255" autocomplete="email" required>
        <small class="admin-help">Used only to sign in to this dashboard. Customer accounts cannot use this form to change role.</small>
      </div>
      <button class="admin-button admin-button--primary" type="submit">Save profile</button>
    </form>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Change password</h3>
    </div>
    <form class="admin-form" method="post" action="<?= e(admin_url('settings/password')) ?>">
      <?= admin_csrf_field() ?>
      <div class="admin-field">
        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="200" required>
      </div>
      <div class="admin-field">
        <label for="new_password">New password</label>
        <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
        <small class="admin-help">At least 12 characters. The new password is hashed with PHP's password API.</small>
      </div>
      <div class="admin-field">
        <label for="confirm_password">Confirm new password</label>
        <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
      </div>
      <button class="admin-button admin-button--primary" type="submit">Update password</button>
    </form>
  </section>
</div>

<section class="admin-panel">
  <div class="admin-panel__head">
    <div>
      <h3>Integration status</h3>
      <p class="admin-muted">Read-only checks. Secret values are never displayed in the dashboard.</p>
    </div>
  </div>
  <dl class="admin-detail-list admin-detail-list--grid">
    <div><dt>Database</dt><dd><span class="admin-badge is-success">Connected</span></dd></div>
    <div><dt>Environment</dt><dd><?= e($integration['app_env'] ?? 'unknown') ?></dd></div>
    <div><dt>Supabase Storage</dt><dd><span class="admin-badge <?= !empty($integration['storage_configured']) ? 'is-success' : 'is-danger' ?>"><?= !empty($integration['storage_configured']) ? 'Configured' : 'Not configured' ?></span></dd></div>
    <div><dt>Storage bucket</dt><dd><?= e($integration['storage_bucket'] ?? 'product-images') ?></dd></div>
    <div><dt>Secure session cookie</dt><dd><span class="admin-badge <?= !empty($integration['secure_cookie']) ? 'is-success' : 'is-warning' ?>"><?= !empty($integration['secure_cookie']) ? 'Yes' : 'No (HTTP local development)' ?></span></dd></div>
    <div><dt>Low-stock threshold</dt><dd><?= e((string) ($integration['low_stock_threshold'] ?? 10)) ?> units</dd></div>
  </dl>
</section>

<section class="admin-panel admin-panel--muted">
  <h3>Static content policy</h3>
  <p class="admin-muted">About Us, branding, header, footer, navigation and other static page content stay in the HTML/CSS source. This dashboard only manages dynamic catalogue, stock and order data.</p>
</section>

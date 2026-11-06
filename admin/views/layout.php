<?php
declare(strict_types=1);

$adminName = trim((string) ($currentAdmin['full_name'] ?? '')) ?: (string) ($currentAdmin['email'] ?? 'Admin');
$adminInitial = strtoupper(mb_substr($adminName, 0, 1));
$navItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => admin_url(''), 'icon' => 'M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6V11h-6v9Zm0-16v5h6V4h-6Z'],
    ['key' => 'products', 'label' => 'Products', 'url' => admin_url('products'), 'icon' => 'M4 7l8-4 8 4v10l-8 4-8-4V7Zm8-1L6 9l6 3 6-3-6-3Zm-6 5v5l5 2.5V13L6 11Zm7 7.5L18 16v-5l-5 2.5v5Z'],
    ['key' => 'categories', 'label' => 'Categories', 'url' => admin_url('categories'), 'icon' => 'M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z'],
    ['key' => 'inventory', 'label' => 'Inventory', 'url' => admin_url('inventory'), 'icon' => 'M3 6l9-3 9 3-9 3-9-3Zm0 4l9 3 9-3v8l-9 3-9-3v-8Zm2 1.5v5.1l7 2.3v-5.1l-7-2.3Zm9 7.4 5-1.7v-5.1l-5 1.7v5.1Z'],
    ['key' => 'orders', 'label' => 'Orders', 'url' => admin_url('orders'), 'icon' => 'M6 2h12v20l-3-2-3 2-3-2-3 2V2Zm3 5h6v2H9V7Zm0 4h6v2H9v-2Zm0 4h4v2H9v-2Z'],
    ['key' => 'customers', 'label' => 'Customers', 'url' => admin_url('customers'), 'icon' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 9a8 8 0 0 1 16 0v1H4v-1Z'],
    ['key' => 'settings', 'label' => 'Settings', 'url' => admin_url('settings'), 'icon' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm9 4-2.1-.6a7 7 0 0 0-.7-1.7l1.1-1.9-1.8-1.8-1.9 1.1a7 7 0 0 0-1.7-.7L13 4h-2l-.6 2.1a7 7 0 0 0-1.7.7L6.8 5.7 5 7.5l1.1 1.9a7 7 0 0 0-.7 1.7L3 12v2l2.1.6a7 7 0 0 0 .7 1.7L4.7 18.2 6.5 20l1.9-1.1a7 7 0 0 0 1.7.7L11 22h2l.6-2.1a7 7 0 0 0 1.7-.7l1.9 1.1 1.8-1.8-1.1-1.9a7 7 0 0 0 .7-1.7L21 14v-2Z'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#1f2329">
  <title><?= e($title) ?> · RedStore Admin</title>
  <link rel="icon" href="<?= e(site_url('assets/images/icons/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap">
  <link rel="stylesheet" href="<?= e(admin_asset('admin.css')) ?>">
  <script src="<?= e(admin_asset('admin.js')) ?>" defer></script>
</head>
<body class="admin-body">
  <a class="admin-skip" href="#admin-main">Skip to content</a>

  <div class="admin-shell">
    <aside class="admin-sidebar" id="admin-sidebar" aria-label="Admin navigation">
      <div class="admin-sidebar__brand">
        <a href="<?= e(admin_url('')) ?>" aria-label="RedStore admin dashboard">
          <img src="<?= e(site_url('assets/images/logo/logo-white.png')) ?>" alt="RedStore" width="125" height="36">
          <span>Admin</span>
        </a>
      </div>

      <nav class="admin-nav">
        <ul>
          <?php foreach ($navItems as $item): ?>
            <li>
              <a href="<?= e($item['url']) ?>" class="<?= $active === $item['key'] ? 'is-active' : '' ?>"<?= $active === $item['key'] ? ' aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="<?= e($item['icon']) ?>"/></svg>
                <span><?= e($item['label']) ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>

      <div class="admin-sidebar__footer">
        <a href="<?= e(site_url('')) ?>" target="_blank" rel="noopener">View storefront</a>
      </div>
    </aside>

    <div class="admin-sidebar-backdrop" data-sidebar-close hidden></div>

    <div class="admin-main">
      <header class="admin-topbar">
        <button class="admin-menu-button" type="button" data-sidebar-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="Open navigation">
          <span></span><span></span><span></span>
        </button>

        <div class="admin-topbar__title">
          <h1><?= e($title) ?></h1>
        </div>

        <div class="admin-topbar__user">
          <span class="admin-avatar" aria-hidden="true"><?= e($adminInitial) ?></span>
          <span class="admin-topbar__identity">
            <strong><?= e($adminName) ?></strong>
            <small><?= e($currentAdmin['email'] ?? '') ?></small>
          </span>
          <form method="post" action="<?= e(admin_url('logout')) ?>" class="admin-logout-form">
            <?= admin_csrf_field() ?>
            <button type="submit" class="admin-button admin-button--ghost admin-button--small">Sign out</button>
          </form>
        </div>
      </header>

      <main class="admin-content" id="admin-main">
        <?php foreach ($flashes as $flash): ?>
          <?php $flashType = in_array($flash['type'] ?? '', ['success', 'error', 'warning', 'info'], true) ? $flash['type'] : 'info'; ?>
          <div class="admin-alert admin-alert--<?= e($flashType) ?>" role="<?= $flashType === 'error' ? 'alert' : 'status' ?>">
            <?= e($flash['message'] ?? '') ?>
          </div>
        <?php endforeach; ?>

        <?= $content ?>
      </main>

      <footer class="admin-footer">
        <p>RedStore Admin · Dynamic catalogue and order management only. Storefront content and branding remain in source code.</p>
      </footer>
    </div>
  </div>
</body>
</html>

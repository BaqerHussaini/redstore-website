<?php
declare(strict_types=1);
$loginError = $error ?? null;
$loginEmail = $email ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#1f2329">
  <title>Admin sign in · RedStore</title>
  <link rel="icon" href="<?= e(site_url('assets/images/icons/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap">
  <link rel="stylesheet" href="<?= e(admin_asset('admin.css')) ?>">
</head>
<body class="admin-login-body">
  <main class="admin-login">
    <section class="admin-login__brand" aria-hidden="true">
      <img src="<?= e(site_url('assets/images/logo/logo-white.png')) ?>" alt="" width="150" height="43">
      <p>Manage the RedStore catalogue, inventory and orders from one secure dashboard.</p>
      <ul>
        <li>Product and gallery management</li>
        <li>Category, variant and stock control</li>
        <li>Order and customer visibility</li>
      </ul>
    </section>

    <section class="admin-login__panel">
      <div class="admin-login__card">
        <p class="admin-eyebrow">RedStore Admin</p>
        <h1>Sign in to the dashboard</h1>
        <p class="admin-muted">Use your administrator email and password. Customer accounts cannot access this area.</p>

        <?php if ($loginError !== null && $loginError !== ''): ?>
          <div class="admin-alert admin-alert--error" role="alert"><?= e($loginError) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(admin_url('login')) ?>" class="admin-form" autocomplete="on">
          <?= admin_csrf_field() ?>
          <div class="admin-field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="username" value="<?= e($loginEmail) ?>" maxlength="255" required autofocus>
          </div>

          <div class="admin-field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" maxlength="200" required>
          </div>

          <button type="submit" class="admin-button admin-button--primary admin-button--block">Sign in</button>
        </form>

        <p class="admin-login__meta">
          <a href="<?= e(site_url('')) ?>">Return to storefront</a>
        </p>
      </div>
    </section>
  </main>
</body>
</html>

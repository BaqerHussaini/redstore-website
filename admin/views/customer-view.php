<?php
declare(strict_types=1);
$customer = $customer ?? [];
$orders = $customer['orders'] ?? [];
$isActive = (bool) ($customer['is_active'] ?? false);
$latestOrder = $orders[0] ?? null;
?>
<div class="admin-page-head">
  <div>
    <p class="admin-eyebrow">Customer</p>
    <h2><?= e($customer['full_name'] ?: 'Unnamed customer') ?></h2>
    <p class="admin-muted"><?= e($customer['email'] ?? '') ?> · Joined <?= e(admin_date($customer['created_at'] ?? null, false)) ?></p>
  </div>
  <div class="admin-page-head__actions">
    <span class="admin-badge <?= $isActive ? 'is-success' : 'is-danger' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
    <a class="admin-button admin-button--ghost" href="<?= e(admin_url('customers')) ?>">Back to customers</a>
  </div>
</div>

<div class="admin-split">
  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Account</h3>
    </div>
    <dl class="admin-detail-list">
      <div><dt>Email</dt><dd><?= e($customer['email'] ?? '—') ?></dd></div>
      <div><dt>Name</dt><dd><?= e($customer['full_name'] ?: 'Not set') ?></dd></div>
      <div><dt>Role</dt><dd><span class="admin-badge is-neutral"><?= e($customer['role'] ?? 'user') ?></span></dd></div>
      <div><dt>Account status</dt><dd><span class="admin-badge <?= $isActive ? 'is-success' : 'is-danger' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span></dd></div>
      <div><dt>Registered</dt><dd><?= e(admin_date($customer['created_at'] ?? null)) ?></dd></div>
      <div><dt>Last updated</dt><dd><?= e(admin_date($customer['updated_at'] ?? null)) ?></dd></div>
    </dl>

    <div class="admin-row-actions">
      <form method="post" action="<?= e(admin_url('customers/' . $customer['id'] . '/status')) ?>" data-confirm="<?= $isActive ? 'Deactivate this customer account? They will not be able to sign in until reactivated. Their order history is preserved.' : 'Reactivate this customer account?' ?>">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>">
        <button class="admin-button <?= $isActive ? 'admin-button--danger' : 'admin-button--primary' ?>" type="submit"><?= $isActive ? 'Deactivate account' : 'Activate account' ?></button>
      </form>
      <a class="admin-button admin-button--ghost" href="<?= e(admin_url('orders') . '?search=' . rawurlencode((string) $customer['email'])) ?>">Find orders</a>
    </div>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Order summary</h3>
    </div>
    <div class="admin-sales-grid">
      <div>
        <span class="admin-muted">Total orders</span>
        <strong><?= e((string) ($customer['order_count'] ?? 0)) ?></strong>
      </div>
      <div>
        <span class="admin-muted">Lifetime value</span>
        <strong><?= e(admin_money($customer['total_spent'] ?? 0)) ?></strong>
      </div>
      <div>
        <span class="admin-muted">Last order</span>
        <strong><?= e(admin_date($customer['last_order_at'] ?? null, false)) ?></strong>
      </div>
    </div>
    <p class="admin-help">Lifetime value excludes cancelled and refunded orders.</p>

    <?php if ($latestOrder !== null): ?>
      <h4>Latest shipping details</h4>
      <address class="admin-address">
        <?= e($latestOrder['shipping_full_name'] ?? '') ?><br>
        <?= e($latestOrder['shipping_address_line1'] ?? '') ?><br>
        <?php if (trim((string) ($latestOrder['shipping_address_line2'] ?? '')) !== ''): ?>
          <?= e($latestOrder['shipping_address_line2']) ?><br>
        <?php endif; ?>
        <?= e($latestOrder['shipping_city'] ?? '') ?><?= trim((string) ($latestOrder['shipping_state'] ?? '')) !== '' ? ', ' . e($latestOrder['shipping_state']) : '' ?> <?= e($latestOrder['shipping_postal_code'] ?? '') ?><br>
        <?= e($latestOrder['shipping_country'] ?? '') ?><br>
        <?= e($latestOrder['shipping_phone'] ?? '') ?>
      </address>
    <?php else: ?>
      <p class="admin-empty-inline">No shipping details are available because this customer has not placed an order.</p>
    <?php endif; ?>
  </section>
</div>

<section class="admin-panel">
  <div class="admin-panel__head">
    <h3>Order history</h3>
    <span class="admin-muted"><?= e((string) count($orders)) ?> order<?= count($orders) === 1 ? '' : 's' ?> shown</span>
  </div>

  <?php if ($orders === []): ?>
    <p class="admin-empty-inline">This customer has not placed any orders yet.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Order</th>
            <th scope="col">Placed</th>
            <th scope="col">Items</th>
            <th scope="col">Total</th>
            <th scope="col">Status</th>
            <th scope="col"><span class="visually-hidden">View</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <tr>
              <td class="admin-table__primary"><?= e($order['order_number']) ?></td>
              <td><?= e(admin_date($order['created_at'])) ?></td>
              <td><?= e((string) $order['item_count']) ?></td>
              <td><?= e(admin_money($order['total_amount'], (string) $order['currency'])) ?></td>
              <td><span class="admin-badge <?= e(admin_status_class((string) $order['status'])) ?>"><?= e($order['status']) ?></span></td>
              <td><a class="admin-button admin-button--ghost admin-button--small" href="<?= e(admin_url('orders/view/' . $order['id'])) ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

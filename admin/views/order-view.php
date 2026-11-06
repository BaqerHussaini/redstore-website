<?php
declare(strict_types=1);
$order = $order ?? [];
$items = $items ?? [];
$statuses = $statuses ?? [];
?>
<div class="admin-page-head">
  <div>
    <p class="admin-eyebrow">Order</p>
    <h2><?= e($order['order_number'] ?? 'Order') ?></h2>
    <p class="admin-muted">Placed <?= e(admin_date($order['created_at'] ?? null)) ?>. Last updated <?= e(admin_date($order['updated_at'] ?? null)) ?>.</p>
  </div>
  <div class="admin-page-head__actions">
    <span class="admin-badge <?= e(admin_status_class((string) ($order['status'] ?? 'pending'))) ?>"><?= e($order['status'] ?? 'pending') ?></span>
    <a class="admin-button admin-button--ghost" href="<?= e(admin_url('orders')) ?>">Back to orders</a>
  </div>
</div>

<div class="admin-split">
  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Update status</h3>
    </div>
    <form class="admin-inline-form" method="post" action="<?= e(admin_url('orders/' . $order['id'] . '/status')) ?>">
      <?= admin_csrf_field() ?>
      <label class="visually-hidden" for="order-status-update">Order status</label>
      <select id="order-status-update" name="status">
        <?php foreach ($statuses as $status): ?>
          <option value="<?= e($status) ?>"<?= ($order['status'] ?? '') === $status ? ' selected' : '' ?>><?= e(ucfirst($status)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="admin-button admin-button--primary" type="submit">Save status</button>
    </form>
    <p class="admin-help">Only valid status transitions are accepted. Order history and item snapshots are preserved.</p>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Customer</h3>
    </div>
    <dl class="admin-detail-list">
      <div><dt>Name</dt><dd><?= e($order['shipping_full_name'] ?? '') ?></dd></div>
      <div><dt>Email</dt><dd><?= e($order['customer_email'] ?? $order['shipping_email'] ?? '') ?></dd></div>
      <div><dt>Account status</dt><dd><span class="admin-badge <?= !empty($order['customer_active']) ? 'is-success' : 'is-danger' ?>"><?= !empty($order['customer_active']) ? 'Active' : 'Inactive' ?></span></dd></div>
      <div><dt>Phone</dt><dd><?= e($order['shipping_phone'] ?? '—') ?></dd></div>
    </dl>
  </section>
</div>

<div class="admin-split">
  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Shipping address</h3>
    </div>
    <address class="admin-address">
      <?= e($order['shipping_full_name'] ?? '') ?><br>
      <?= e($order['shipping_address_line1'] ?? '') ?><br>
      <?php if (trim((string) ($order['shipping_address_line2'] ?? '')) !== ''): ?>
        <?= e($order['shipping_address_line2']) ?><br>
      <?php endif; ?>
      <?= e($order['shipping_city'] ?? '') ?><?= trim((string) ($order['shipping_state'] ?? '')) !== '' ? ', ' . e($order['shipping_state']) : '' ?> <?= e($order['shipping_postal_code'] ?? '') ?><br>
      <?= e($order['shipping_country'] ?? '') ?><br>
      <?= e($order['shipping_email'] ?? '') ?><br>
      <?= e($order['shipping_phone'] ?? '') ?>
    </address>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Order notes</h3>
    </div>
    <?php if (trim((string) ($order['notes'] ?? '')) !== ''): ?>
      <p class="admin-note"><?= nl2br(e($order['notes'])) ?></p>
    <?php else: ?>
      <p class="admin-empty-inline">No notes were provided with this order.</p>
    <?php endif; ?>
  </section>
</div>

<section class="admin-panel">
  <div class="admin-panel__head">
    <h3>Items</h3>
    <span class="admin-muted"><?= e((string) count($items)) ?> line item<?= count($items) === 1 ? '' : 's' ?></span>
  </div>

  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th scope="col">Product</th>
          <th scope="col">Variant</th>
          <th scope="col">Unit price</th>
          <th scope="col">Quantity</th>
          <th scope="col">Line total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td>
              <div class="admin-product-cell">
                <img src="<?= e(admin_image_url($item['product_image_url'] ?? null)) ?>" alt="" width="44" height="55" loading="lazy">
                <div>
                  <span class="admin-table__primary"><?= e($item['product_name']) ?></span>
                  <?php if (!empty($item['product_id'])): ?>
                    <a class="admin-table__secondary" href="<?= e(admin_url('products/edit/' . $item['product_id'])) ?>">Open product</a>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td><?= e($item['variant_size'] ?? '—') ?></td>
            <td><?= e(admin_money($item['unit_price'], (string) ($order['currency'] ?? 'USD'))) ?></td>
            <td><?= e((string) $item['quantity']) ?></td>
            <td><?= e(admin_money($item['line_total'], (string) ($order['currency'] ?? 'USD'))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="admin-order-totals">
    <div><span>Subtotal</span><strong><?= e(admin_money($order['subtotal'] ?? 0, (string) ($order['currency'] ?? 'USD'))) ?></strong></div>
    <div><span>Shipping</span><strong><?= e(admin_money($order['shipping_fee'] ?? 0, (string) ($order['currency'] ?? 'USD'))) ?></strong></div>
    <div class="admin-order-totals__grand"><span>Total</span><strong><?= e(admin_money($order['total_amount'] ?? 0, (string) ($order['currency'] ?? 'USD'))) ?></strong></div>
  </div>
</section>

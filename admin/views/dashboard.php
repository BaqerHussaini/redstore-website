<?php
declare(strict_types=1);
$stats = $stats ?? [];
$recentOrders = $recent_orders ?? [];
$recentCustomers = $recent_customers ?? [];
$recentProducts = $recent_products ?? [];
$bestSellers = $best_sellers ?? [];
$lowStock = $low_stock ?? [];
$statuses = $order_statuses ?? [];
$threshold = (int) ($low_stock_threshold ?? 10);
$cards = [
    ['label' => 'Recorded revenue', 'value' => admin_money($stats['sales_total'] ?? 0), 'href' => admin_url('orders'), 'tone' => 'green'],
    ['label' => 'Total products', 'value' => (int) ($stats['total_products'] ?? 0), 'href' => admin_url('products'), 'tone' => 'brand'],
    ['label' => 'Active products', 'value' => (int) ($stats['active_products'] ?? 0), 'href' => admin_url('products') . '?status=active', 'tone' => 'green'],
    ['label' => 'Categories', 'value' => (int) ($stats['total_categories'] ?? 0), 'href' => admin_url('categories'), 'tone' => 'blue'],
    ['label' => 'Total orders', 'value' => (int) ($stats['total_orders'] ?? 0), 'href' => admin_url('orders'), 'tone' => 'violet'],
    ['label' => 'Pending orders', 'value' => (int) ($stats['pending_orders'] ?? 0), 'href' => admin_url('orders') . '?status=pending', 'tone' => 'amber'],
    ['label' => 'Customers', 'value' => (int) ($stats['total_customers'] ?? 0), 'href' => admin_url('customers'), 'tone' => 'teal'],
    ['label' => 'Low-stock products', 'value' => (int) ($stats['low_stock_products'] ?? 0), 'href' => admin_url('inventory') . '?low_stock=1', 'tone' => 'red'],
];
?>
<div class="admin-page-head">
  <div>
    <p class="admin-eyebrow">Overview</p>
    <h2>Store performance</h2>
    <p class="admin-muted">Live figures from the existing products, categories, orders and customer tables.</p>
  </div>
  <a class="admin-button admin-button--primary" href="<?= e(admin_url('products/create')) ?>">Add product</a>
</div>

<section class="admin-stat-grid" aria-label="Store summary">
  <?php foreach ($cards as $card): ?>
    <a class="admin-stat admin-stat--<?= e($card['tone']) ?>" href="<?= e($card['href']) ?>">
      <span class="admin-stat__label"><?= e($card['label']) ?></span>
      <strong class="admin-stat__value"><?= e((string) $card['value']) ?></strong>
      <span class="admin-stat__hint">View</span>
    </a>
  <?php endforeach; ?>
</section>

<section class="admin-panel">
  <div class="admin-panel__head">
    <div>
      <h3>Sales summary</h3>
      <p class="admin-muted">Revenue counts orders marked paid, processing, shipped or delivered. Pending, cancelled and refunded orders are excluded.</p>
    </div>
  </div>
  <div class="admin-sales-grid">
    <div>
      <span class="admin-muted">Recorded sales</span>
      <strong><?= e(admin_money($stats['sales_total'] ?? 0)) ?></strong>
    </div>
    <div>
      <span class="admin-muted">This month</span>
      <strong><?= e(admin_money($stats['sales_this_month'] ?? 0)) ?></strong>
    </div>
    <div>
      <span class="admin-muted">Orders this month</span>
      <strong><?= e((string) ($stats['orders_this_month'] ?? 0)) ?></strong>
    </div>
  </div>
  <?php if ((int) ($stats['total_orders'] ?? 0) === 0): ?>
    <p class="admin-empty-inline">There are no orders yet. Sales will appear here as real orders are recorded.</p>
  <?php endif; ?>
</section>

<div class="admin-split">
  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Recent orders</h3>
      <a class="admin-link" href="<?= e(admin_url('orders')) ?>">All orders</a>
    </div>

    <?php if ($recentOrders === []): ?>
      <p class="admin-empty-inline">No orders have been placed yet.</p>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table admin-table--compact">
          <thead>
            <tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th><th>Placed</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentOrders as $order): ?>
              <tr>
                <td><a class="admin-link" href="<?= e(admin_url('orders/view/' . $order['id'])) ?>"><?= e($order['order_number']) ?></a></td>
                <td>
                  <span class="admin-table__primary"><?= e($order['shipping_full_name']) ?></span>
                  <span class="admin-table__secondary"><?= e($order['customer_email']) ?></span>
                </td>
                <td><?= e(admin_money($order['total_amount'], (string) $order['currency'])) ?></td>
                <td><span class="admin-badge <?= e(admin_status_class((string) $order['status'])) ?>"><?= e($order['status']) ?></span></td>
                <td><?= e(admin_date($order['created_at'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h3>Low stock</h3>
        <p class="admin-muted">At or below <?= e((string) $threshold) ?> units.</p>
      </div>
      <a class="admin-link" href="<?= e(admin_url('inventory') . '?low_stock=1') ?>">Inventory</a>
    </div>

    <?php if ($lowStock === []): ?>
      <p class="admin-empty-inline">No products are at or below the low-stock threshold.</p>
    <?php else: ?>
      <ul class="admin-stock-list">
        <?php foreach ($lowStock as $product): ?>
          <li>
            <img src="<?= e(admin_image_url($product['main_image_url'] ?? $product['image_url'])) ?>" alt="" width="44" height="55" loading="lazy">
            <div>
              <a class="admin-link" href="<?= e(admin_url('products/edit/' . $product['id'])) ?>"><?= e($product['name']) ?></a>
              <span class="admin-table__secondary"><?= e($product['category_name'] ?? 'Uncategorised') ?></span>
            </div>
            <span class="admin-badge <?= (int) $product['stock_quantity'] === 0 ? 'is-danger' : 'is-warning' ?>"><?= e((string) $product['stock_quantity']) ?> left</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<div class="admin-split">
  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Recent customers</h3>
      <a class="admin-link" href="<?= e(admin_url('customers')) ?>">All customers</a>
    </div>

    <?php if ($recentCustomers === []): ?>
      <p class="admin-empty-inline">No customer accounts have been created yet.</p>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table admin-table--compact">
          <thead>
            <tr><th>Customer</th><th>Orders</th><th>Joined</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentCustomers as $customer): ?>
              <tr>
                <td>
                  <a class="admin-link admin-table__primary" href="<?= e(admin_url('customers/view/' . $customer['id'])) ?>"><?= e($customer['full_name'] ?: 'Unnamed customer') ?></a>
                  <span class="admin-table__secondary"><?= e($customer['email']) ?></span>
                </td>
                <td><?= e((string) $customer['order_count']) ?></td>
                <td><?= e(admin_date($customer['created_at'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Recently added products</h3>
      <a class="admin-link" href="<?= e(admin_url('products')) ?>">All products</a>
    </div>

    <?php if ($recentProducts === []): ?>
      <p class="admin-empty-inline">No products have been added yet.</p>
    <?php else: ?>
      <ul class="admin-stock-list">
        <?php foreach ($recentProducts as $product): ?>
          <li>
            <img src="<?= e(admin_image_url($product['main_image_url'] ?? $product['image_url'])) ?>" alt="" width="44" height="55" loading="lazy">
            <div>
              <a class="admin-link" href="<?= e(admin_url('products/edit/' . $product['id'])) ?>"><?= e($product['name']) ?></a>
              <span class="admin-table__secondary"><?= e($product['category_name'] ?? 'Uncategorised') ?> · <?= e(admin_money($product['sale_price'] ?? $product['price'])) ?></span>
            </div>
            <span class="admin-badge <?= !empty($product['is_active']) ? 'is-success' : 'is-danger' ?>"><?= !empty($product['is_active']) ? 'Active' : 'Inactive' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<section class="admin-panel">
  <div class="admin-panel__head">
    <div>
      <h3>Best-selling products</h3>
      <p class="admin-muted">Based on units sold in non-cancelled, non-refunded orders.</p>
    </div>
  </div>

  <?php if ($bestSellers === []): ?>
    <p class="admin-empty-inline">No sales data is available yet.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Product</th><th>Units sold</th><th>Revenue</th><th><span class="visually-hidden">Open</span></th></tr>
        </thead>
        <tbody>
          <?php foreach ($bestSellers as $seller): ?>
            <tr>
              <td>
                <div class="admin-product-cell">
                  <img src="<?= e(admin_image_url($seller['main_image_url'] ?? null)) ?>" alt="" width="44" height="55" loading="lazy">
                  <div>
                    <span class="admin-table__primary"><?= e($seller['product_name']) ?></span>
                    <?php if (!empty($seller['slug'])): ?>
                      <span class="admin-table__secondary">/<?= e($seller['slug']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td><?= e((string) $seller['units_sold']) ?></td>
              <td><?= e(admin_money($seller['revenue'])) ?></td>
              <td>
                <?php if (!empty($seller['product_id'])): ?>
                  <a class="admin-button admin-button--ghost admin-button--small" href="<?= e(admin_url('products/edit/' . $seller['product_id'])) ?>">Open</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="admin-panel">
  <div class="admin-panel__head">
    <h3>Orders by status</h3>
  </div>
  <div class="admin-chip-row">
    <?php foreach ($statuses as $status => $count): ?>
      <a class="admin-chip" href="<?= e(admin_url('orders') . '?status=' . rawurlencode((string) $status)) ?>">
        <span class="admin-badge <?= e(admin_status_class((string) $status)) ?>"><?= e($status) ?></span>
        <strong><?= e((string) $count) ?></strong>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php
declare(strict_types=1);
$orders = $orders ?? [];
$meta = $meta ?? [];
$filters = $meta['filters'] ?? [];
$statuses = $meta['statuses'] ?? [];
$currentPage = (int) ($meta['page'] ?? 1);
$totalPages = (int) ($meta['total_pages'] ?? 0);
$total = (int) ($meta['total'] ?? 0);
$paginationQuery = [
    'search' => $filters['search'] ?? '',
    'status' => $filters['status'] ?? '',
    'date_from' => $filters['date_from'] ?? '',
    'date_to' => $filters['date_to'] ?? '',
    'sort' => $filters['sort'] ?? 'newest',
];
$pageUrl = static fn (int $page): string => admin_url('orders') . '?' . http_build_query(array_merge($paginationQuery, ['page' => $page]));
$sortOptions = [
    'newest' => 'Newest first',
    'oldest' => 'Oldest first',
    'total_desc' => 'Total high–low',
    'total_asc' => 'Total low–high',
    'updated' => 'Recently updated',
];
?>
<div class="admin-page-head">
  <div>
    <p class="admin-eyebrow">Sales</p>
    <h2>Orders</h2>
    <p class="admin-muted"><?= e((string) $total) ?> order<?= $total === 1 ? '' : 's' ?>. Order items are snapshotted, so editing a product never rewrites past orders.</p>
  </div>
</div>

<section class="admin-panel">
  <form class="admin-filters admin-filters--compact" method="get" action="<?= e(admin_url('orders')) ?>">
    <div class="admin-field admin-field--grow">
      <label for="order-search">Search</label>
      <input id="order-search" name="search" type="search" value="<?= e($filters['search'] ?? '') ?>" maxlength="120" placeholder="Order number, customer name or email">
    </div>
    <div class="admin-field">
      <label for="order-status">Status</label>
      <select id="order-status" name="status">
        <option value="">All statuses</option>
        <?php foreach ($statuses as $status): ?>
          <option value="<?= e($status) ?>"<?= ($filters['status'] ?? '') === $status ? ' selected' : '' ?>><?= e(ucfirst($status)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="order-date-from">From</label>
      <input id="order-date-from" name="date_from" type="date" value="<?= e($filters['date_from'] ?? '') ?>">
    </div>
    <div class="admin-field">
      <label for="order-date-to">To</label>
      <input id="order-date-to" name="date_to" type="date" value="<?= e($filters['date_to'] ?? '') ?>">
    </div>
    <div class="admin-field">
      <label for="order-sort">Sort</label>
      <select id="order-sort" name="sort">
        <?php foreach ($sortOptions as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= ($filters['sort'] ?? 'newest') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-filter-actions">
      <button class="admin-button admin-button--primary" type="submit">Apply</button>
      <a class="admin-button admin-button--ghost" href="<?= e(admin_url('orders')) ?>">Reset</a>
    </div>
  </form>
</section>

<section class="admin-panel">
  <?php if ($orders === []): ?>
    <p class="admin-empty-inline">No orders match these filters.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Order</th>
            <th scope="col">Customer</th>
            <th scope="col">Items</th>
            <th scope="col">Total</th>
            <th scope="col">Status</th>
            <th scope="col">Placed</th>
            <th scope="col"><span class="visually-hidden">View</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <tr>
              <td>
                <a class="admin-link admin-table__primary" href="<?= e(admin_url('orders/view/' . $order['id'])) ?>"><?= e($order['order_number']) ?></a>
              </td>
              <td>
                <span class="admin-table__primary"><?= e($order['shipping_full_name']) ?></span>
                <span class="admin-table__secondary"><?= e($order['customer_email']) ?></span>
              </td>
              <td><?= e((string) $order['item_count']) ?></td>
              <td><?= e(admin_money($order['total_amount'], (string) $order['currency'])) ?></td>
              <td><span class="admin-badge <?= e(admin_status_class((string) $order['status'])) ?>"><?= e($order['status']) ?></span></td>
              <td><?= e(admin_date($order['created_at'])) ?></td>
              <td><a class="admin-button admin-button--ghost admin-button--small" href="<?= e(admin_url('orders/view/' . $order['id'])) ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="admin-pagination" aria-label="Order pages">
        <?php if ($currentPage > 1): ?>
          <a class="admin-button admin-button--ghost admin-button--small" href="<?= e($pageUrl($currentPage - 1)) ?>" rel="prev">Previous</a>
        <?php endif; ?>
        <span>Page <?= e((string) $currentPage) ?> of <?= e((string) $totalPages) ?></span>
        <?php if ($currentPage < $totalPages): ?>
          <a class="admin-button admin-button--ghost admin-button--small" href="<?= e($pageUrl($currentPage + 1)) ?>" rel="next">Next</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>

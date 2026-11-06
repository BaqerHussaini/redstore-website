<?php
declare(strict_types=1);
$customers = $customers ?? [];
$meta = $meta ?? [];
$filters = $meta['filters'] ?? [];
$currentPage = (int) ($meta['page'] ?? 1);
$totalPages = (int) ($meta['total_pages'] ?? 0);
$total = (int) ($meta['total'] ?? 0);
$paginationQuery = [
    'search' => $filters['search'] ?? '',
    'status' => $filters['status'] ?? '',
    'sort' => $filters['sort'] ?? 'newest',
];
$pageUrl = static fn (int $page): string => admin_url('customers') . '?' . http_build_query(array_merge($paginationQuery, ['page' => $page]));
$sortOptions = [
    'newest' => 'Newest first',
    'oldest' => 'Oldest first',
    'name_asc' => 'Name A–Z',
    'name_desc' => 'Name Z–A',
    'orders_desc' => 'Most orders',
    'spent_desc' => 'Highest spend',
];
?>
<div class="admin-page-head">
  <div>
    <p class="admin-eyebrow">Accounts</p>
    <h2>Customers</h2>
    <p class="admin-muted"><?= e((string) $total) ?> customer account<?= $total === 1 ? '' : 's' ?>. Password hashes and authentication secrets are never displayed or returned by this page.</p>
  </div>
</div>

<section class="admin-panel">
  <form class="admin-filters" method="get" action="<?= e(admin_url('customers')) ?>">
    <div class="admin-field admin-field--grow">
      <label for="customer-search">Search</label>
      <input id="customer-search" name="search" type="search" value="<?= e($filters['search'] ?? '') ?>" maxlength="120" placeholder="Customer name or email">
    </div>
    <div class="admin-field">
      <label for="customer-status">Status</label>
      <select id="customer-status" name="status">
        <option value="">All statuses</option>
        <option value="active"<?= ($filters['status'] ?? '') === 'active' ? ' selected' : '' ?>>Active</option>
        <option value="inactive"<?= ($filters['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="admin-field">
      <label for="customer-sort">Sort</label>
      <select id="customer-sort" name="sort">
        <?php foreach ($sortOptions as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= ($filters['sort'] ?? 'newest') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-filter-actions">
      <button class="admin-button admin-button--primary" type="submit">Apply</button>
      <a class="admin-button admin-button--ghost" href="<?= e(admin_url('customers')) ?>">Reset</a>
    </div>
  </form>
</section>

<section class="admin-panel">
  <?php if ($customers === []): ?>
    <p class="admin-empty-inline">No customer accounts match these filters.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Customer</th>
            <th scope="col">Status</th>
            <th scope="col">Orders</th>
            <th scope="col">Lifetime value</th>
            <th scope="col">Last order</th>
            <th scope="col">Joined</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customers as $customer): ?>
            <?php $isActive = (bool) $customer['is_active']; ?>
            <tr>
              <td>
                <a class="admin-link admin-table__primary" href="<?= e(admin_url('customers/view/' . $customer['id'])) ?>"><?= e($customer['full_name'] ?: 'Unnamed customer') ?></a>
                <span class="admin-table__secondary"><?= e($customer['email']) ?></span>
              </td>
              <td><span class="admin-badge <?= $isActive ? 'is-success' : 'is-danger' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span></td>
              <td>
                <span><?= e((string) $customer['order_count']) ?></span>
                <?php if ((int) $customer['valid_order_count'] !== (int) $customer['order_count']): ?>
                  <span class="admin-table__secondary"><?= e((string) $customer['valid_order_count']) ?> valid</span>
                <?php endif; ?>
              </td>
              <td><?= e(admin_money($customer['total_spent'])) ?></td>
              <td><?= e(admin_date($customer['last_order_at'] ?? null)) ?></td>
              <td><?= e(admin_date($customer['created_at'], false)) ?></td>
              <td>
                <div class="admin-row-actions">
                  <a class="admin-button admin-button--ghost admin-button--small" href="<?= e(admin_url('customers/view/' . $customer['id'])) ?>">View</a>
                  <form method="post" action="<?= e(admin_url('customers/' . $customer['id'] . '/status')) ?>" data-confirm="<?= $isActive ? 'Deactivate this customer account? They will not be able to sign in until reactivated. Their order history is preserved.' : 'Reactivate this customer account?' ?>">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                    <button class="admin-button <?= $isActive ? 'admin-button--danger' : 'admin-button--primary' ?> admin-button--small" type="submit"><?= $isActive ? 'Deactivate' : 'Activate' ?></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="admin-pagination" aria-label="Customer pages">
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

<section class="admin-panel admin-panel--muted">
  <h3>Role management</h3>
  <p class="admin-muted">Customer role promotion is intentionally not available here. Create additional administrator accounts with the server-side CLI procedure documented in the README.</p>
</section>

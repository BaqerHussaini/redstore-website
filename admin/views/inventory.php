<?php
declare(strict_types=1);
$items = $items ?? [];
$meta = $meta ?? [];
$filters = $meta['filters'] ?? [];
$threshold = (int) ($meta['threshold'] ?? 10);
$currentPage = (int) ($meta['page'] ?? 1);
$totalPages = (int) ($meta['total_pages'] ?? 0);
$total = (int) ($meta['total'] ?? 0);
$pageUrl = static function (int $page) use ($filters): string {
    return admin_url('inventory') . '?' . http_build_query([
        'search' => $filters['search'] ?? '',
        'stock_status' => $filters['stock_status'] ?? '',
        'page' => $page,
    ]);
};
?>
<div class="admin-page-head">
  <div>
    <p class="admin-eyebrow">Stock</p>
    <h2>Inventory</h2>
    <p class="admin-muted"><?= e((string) $total) ?> product<?= $total === 1 ? '' : 's' ?>. Low-stock threshold: <?= e((string) $threshold) ?> units. Variant products calculate their total from variant stock.</p>
  </div>
</div>

<section class="admin-panel">
  <form class="admin-filters admin-filters--compact" method="get" action="<?= e(admin_url('inventory')) ?>">
    <div class="admin-field admin-field--grow">
      <label for="inventory-search">Search</label>
      <input id="inventory-search" name="search" type="search" value="<?= e($filters['search'] ?? '') ?>" maxlength="120" placeholder="Product name or slug">
    </div>
    <div class="admin-field">
      <label for="inventory-stock-status">Stock status</label>
      <select id="inventory-stock-status" name="stock_status">
        <option value="">All stock levels</option>
        <option value="in"<?= ($filters['stock_status'] ?? '') === 'in' ? ' selected' : '' ?>>In stock</option>
        <option value="low"<?= ($filters['stock_status'] ?? '') === 'low' ? ' selected' : '' ?>>Low stock</option>
        <option value="out"<?= ($filters['stock_status'] ?? '') === 'out' ? ' selected' : '' ?>>Out of stock</option>
      </select>
    </div>
    <div class="admin-filter-actions">
      <button class="admin-button admin-button--primary" type="submit">Apply</button>
      <a class="admin-button admin-button--ghost" href="<?= e(admin_url('inventory')) ?>">Reset</a>
    </div>
  </form>
</section>

<section class="admin-panel">
  <?php if ($items === []): ?>
    <p class="admin-empty-inline">No products match this inventory filter.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table--inventory">
        <thead>
          <tr>
            <th scope="col">Product</th>
            <th scope="col">Category</th>
            <th scope="col">Total stock</th>
            <th scope="col">Variants / update</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <?php
            $stock = (int) $item['stock_quantity'];
            $variants = is_array($item['variants'] ?? null) ? $item['variants'] : [];
            $isLow = $stock <= $threshold;
            ?>
            <tr class="<?= $isLow ? 'is-low-stock' : '' ?>">
              <td>
                <div class="admin-product-cell">
                  <img src="<?= e(admin_image_url($item['main_image_url'] ?? $item['image_url'])) ?>" alt="" width="44" height="55" loading="lazy">
                  <div>
                    <a class="admin-link admin-table__primary" href="<?= e(admin_url('products/edit/' . $item['id'])) ?>"><?= e($item['name']) ?></a>
                    <span class="admin-table__secondary">/<?= e($item['slug']) ?></span>
                  </div>
                </div>
              </td>
              <td><?= e($item['category_name'] ?? 'Uncategorised') ?></td>
              <td>
                <span class="admin-badge <?= $stock === 0 ? 'is-danger' : ($isLow ? 'is-warning' : 'is-success') ?>"><?= e((string) $stock) ?> units</span>
                <?php if ($isLow): ?><span class="admin-table__secondary">Low stock</span><?php endif; ?>
              </td>
              <td>
                <?php if ($variants === []): ?>
                  <form class="admin-inline-form" method="post" action="<?= e(admin_url('inventory/product/' . $item['id'])) ?>">
                    <?= admin_csrf_field() ?>
                    <label class="visually-hidden" for="stock-<?= e($item['id']) ?>">Stock for <?= e($item['name']) ?></label>
                    <input id="stock-<?= e($item['id']) ?>" name="stock_quantity" type="number" min="0" max="1000000" step="1" value="<?= e((string) $stock) ?>">
                    <button class="admin-button admin-button--primary admin-button--small" type="submit">Update</button>
                  </form>
                <?php else: ?>
                  <ul class="admin-variant-stock-list">
                    <?php foreach ($variants as $variant): ?>
                      <li>
                        <span class="admin-variant-label">
                          <?= e($variant['size']) ?><?= trim((string) $variant['color']) !== '' ? ' · ' . e($variant['color']) : '' ?>
                          <?php if (trim((string) ($variant['sku'] ?? '')) !== ''): ?>
                            <small><?= e($variant['sku']) ?></small>
                          <?php endif; ?>
                        </span>
                        <form class="admin-inline-form" method="post" action="<?= e(admin_url('inventory/variant/' . $variant['id'])) ?>">
                          <?= admin_csrf_field() ?>
                          <label class="visually-hidden" for="variant-stock-<?= e($variant['id']) ?>">Stock for <?= e($variant['size']) ?></label>
                          <input id="variant-stock-<?= e($variant['id']) ?>" name="stock_quantity" type="number" min="0" max="1000000" step="1" value="<?= e((string) $variant['stock_quantity']) ?>">
                          <button class="admin-button admin-button--ghost admin-button--small" type="submit">Save</button>
                        </form>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </td>
              <td><span class="admin-badge <?= (bool) $item['is_active'] ? 'is-success' : 'is-danger' ?>"><?= (bool) $item['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="admin-pagination" aria-label="Inventory pages">
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

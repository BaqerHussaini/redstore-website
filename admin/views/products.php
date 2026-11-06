<?php
declare(strict_types=1);
$products = $products ?? [];
$meta = $meta ?? [];
$categories = $categories ?? [];
$filters = $meta['filters'] ?? [];
$currentPage = (int) ($meta['page'] ?? 1);
$totalPages = (int) ($meta['total_pages'] ?? 0);
$total = (int) ($meta['total'] ?? 0);

$paginationQuery = [
    'search' => $filters['search'] ?? '',
    'category_id' => $filters['category_id'] ?? '',
    'status' => $filters['status'] ?? '',
    'featured' => $filters['featured'] ?? '',
    'sort' => $filters['sort'] ?? 'newest',
];
$pageUrl = static function (int $page) use ($paginationQuery): string {
    return admin_url('products') . '?' . http_build_query(array_merge($paginationQuery, ['page' => $page]));
};
?>
<div class="admin-page-head">
  <div>
    <p class="admin-eyebrow">Catalogue</p>
    <h2>Products</h2>
    <p class="admin-muted"><?= e((string) $total) ?> product<?= $total === 1 ? '' : 's' ?> found. Changes appear on the storefront immediately through the PHP API.</p>
  </div>
  <a class="admin-button admin-button--primary" href="<?= e(admin_url('products/create')) ?>">Add product</a>
</div>

<section class="admin-panel">
  <form class="admin-filters" method="get" action="<?= e(admin_url('products')) ?>">
    <div class="admin-field admin-field--grow">
      <label for="search">Search</label>
      <input id="search" name="search" type="search" value="<?= e($filters['search'] ?? '') ?>" placeholder="Name, slug or description" maxlength="120">
    </div>

    <div class="admin-field">
      <label for="category_id">Category</label>
      <select id="category_id" name="category_id">
        <option value="">All categories</option>
        <?php foreach ($categories as $category): ?>
          <option value="<?= e($category['id']) ?>"<?= ($filters['category_id'] ?? '') === (string) $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="admin-field">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">All statuses</option>
        <option value="active"<?= ($filters['status'] ?? '') === 'active' ? ' selected' : '' ?>>Active</option>
        <option value="inactive"<?= ($filters['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option>
      </select>
    </div>

    <div class="admin-field">
      <label for="featured">Featured</label>
      <select id="featured" name="featured">
        <option value="">All</option>
        <option value="1"<?= ($filters['featured'] ?? '') === '1' ? ' selected' : '' ?>>Featured</option>
        <option value="0"<?= ($filters['featured'] ?? '') === '0' ? ' selected' : '' ?>>Not featured</option>
      </select>
    </div>

    <div class="admin-field">
      <label for="sort">Sort</label>
      <select id="sort" name="sort">
        <?php
        $sortOptions = [
            'newest' => 'Newest first',
            'oldest' => 'Oldest first',
            'name_asc' => 'Name A–Z',
            'name_desc' => 'Name Z–A',
            'price_asc' => 'Price low–high',
            'price_desc' => 'Price high–low',
            'stock_asc' => 'Stock low–high',
            'stock_desc' => 'Stock high–low',
        ];
        foreach ($sortOptions as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= ($filters['sort'] ?? 'newest') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="admin-filter-actions">
      <button class="admin-button admin-button--primary" type="submit">Apply</button>
      <a class="admin-button admin-button--ghost" href="<?= e(admin_url('products')) ?>">Reset</a>
    </div>
  </form>
</section>

<section class="admin-panel">
  <?php if ($products === []): ?>
    <div class="admin-empty">
      <h3>No products match these filters</h3>
      <p>Try a different search or clear the filters. You can also add a new product.</p>
      <a class="admin-button admin-button--primary" href="<?= e(admin_url('products/create')) ?>">Add product</a>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Product</th>
            <th scope="col">Category</th>
            <th scope="col">Price</th>
            <th scope="col">Stock</th>
            <th scope="col">Status</th>
            <th scope="col">Featured</th>
            <th scope="col">Created</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $product): ?>
            <?php
            $image = $product['main_image_url'] ?? $product['image_url'] ?? null;
            $price = (float) $product['price'];
            $salePrice = $product['sale_price'] !== null ? (float) $product['sale_price'] : null;
            $stock = (int) $product['stock_quantity'];
            ?>
            <tr>
              <td>
                <div class="admin-product-cell">
                  <img src="<?= e(admin_image_url($image)) ?>" alt="" width="48" height="60" loading="lazy">
                  <div>
                    <a class="admin-link admin-table__primary" href="<?= e(admin_url('products/edit/' . $product['id'])) ?>"><?= e($product['name']) ?></a>
                    <span class="admin-table__secondary">/<?= e($product['slug']) ?></span>
                  </div>
                </div>
              </td>
              <td><?= e($product['category_name'] ?? 'Uncategorised') ?></td>
              <td>
                <?php if ($salePrice !== null): ?>
                  <span class="admin-table__primary"><?= e(admin_money($salePrice)) ?></span>
                  <span class="admin-table__secondary"><s><?= e(admin_money($price)) ?></s></span>
                <?php else: ?>
                  <?= e(admin_money($price)) ?>
                <?php endif; ?>
              </td>
              <td>
                <span class="admin-badge <?= $stock === 0 ? 'is-danger' : ($stock <= 10 ? 'is-warning' : 'is-success') ?>"><?= e((string) $stock) ?></span>
                <?php if ((int) ($product['variant_count'] ?? 0) > 0): ?>
                  <span class="admin-table__secondary"><?= e((string) $product['variant_count']) ?> variants</span>
                <?php endif; ?>
              </td>
              <td><span class="admin-badge <?= (bool) $product['is_active'] ? 'is-success' : 'is-danger' ?>"><?= (bool) $product['is_active'] ? 'Active' : 'Inactive' ?></span></td>
              <td><?= (bool) $product['is_featured'] ? '<span class="admin-badge is-brand">Featured</span>' : '<span class="admin-muted">—</span>' ?></td>
              <td><?= e(admin_date($product['created_at'], false)) ?></td>
              <td>
                <div class="admin-row-actions">
                  <a class="admin-button admin-button--ghost admin-button--small" href="<?= e(admin_url('products/edit/' . $product['id'])) ?>">Edit</a>

                  <?php if ((bool) $product['is_active']): ?>
                    <form method="post" action="<?= e(admin_url('products/delete/' . $product['id'])) ?>" data-confirm="Deactivate this product? It will disappear from the storefront but its data and order history stay intact.">
                      <?= admin_csrf_field() ?>
                      <input type="hidden" name="mode" value="deactivate">
                      <button class="admin-button admin-button--ghost admin-button--small" type="submit">Deactivate</button>
                    </form>
                  <?php endif; ?>

                  <form method="post" action="<?= e(admin_url('products/delete/' . $product['id'])) ?>" data-confirm="Permanently delete this product and its gallery? This is only allowed when the product has no order history and cannot be undone.">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="mode" value="delete">
                    <button class="admin-button admin-button--danger admin-button--small" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="admin-pagination" aria-label="Product pages">
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

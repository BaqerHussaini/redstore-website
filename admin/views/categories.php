<?php
declare(strict_types=1);
$categories = $categories ?? [];
$allCategories = $all_categories ?? $categories;
$meta = $meta ?? [];
$filters = $meta['filters'] ?? [];
$currentPage = (int) ($meta['page'] ?? 1);
$totalPages = (int) ($meta['total_pages'] ?? 0);
$total = (int) ($meta['total'] ?? 0);
$pageUrl = static fn (int $page): string => admin_url('categories') . '?' . http_build_query(['search' => $filters['search'] ?? '', 'page' => $page]);
?>
<div class="admin-page-head">
  <div>
    <p class="admin-eyebrow">Catalogue</p>
    <h2>Categories</h2>
    <p class="admin-muted"><?= e((string) $total) ?> categor<?= $total === 1 ? 'y' : 'ies' ?>. Products can be reassigned before a category is deleted.</p>
  </div>
</div>

<div class="admin-split admin-split--aside">
  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Add category</h3>
    </div>
    <form class="admin-form" method="post" action="<?= e(admin_url('categories/create')) ?>">
      <?= admin_csrf_field() ?>
      <div class="admin-field">
        <label for="new-category-name">Name</label>
        <input id="new-category-name" name="name" type="text" maxlength="80" required>
      </div>
      <div class="admin-field">
        <label for="new-category-slug">Slug (optional)</label>
        <input id="new-category-slug" name="slug" type="text" maxlength="100" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" data-slug-target data-slug-name="new-category-name">
        <small class="admin-help">Leave blank to generate a unique slug.</small>
      </div>
      <button class="admin-button admin-button--primary" type="submit">Create category</button>
    </form>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h3>Category list</h3>
        <p class="admin-muted">Editing a slug changes the storefront category filter URL.</p>
      </div>
    </div>

    <form class="admin-filters admin-filters--compact" method="get" action="<?= e(admin_url('categories')) ?>">
      <div class="admin-field admin-field--grow">
        <label for="category-search">Search</label>
        <input id="category-search" name="search" type="search" value="<?= e($filters['search'] ?? '') ?>" maxlength="80" placeholder="Category name or slug">
      </div>
      <div class="admin-filter-actions">
        <button class="admin-button admin-button--primary" type="submit">Search</button>
        <a class="admin-button admin-button--ghost" href="<?= e(admin_url('categories')) ?>">Reset</a>
      </div>
    </form>

    <?php if ($categories === []): ?>
      <p class="admin-empty-inline">No categories found.</p>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table admin-table--categories">
          <thead>
            <tr>
              <th scope="col">Name</th>
              <th scope="col">Slug</th>
              <th scope="col">Products</th>
              <th scope="col">Created</th>
              <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $category): ?>
              <?php $editFormId = 'category-edit-' . $category['id']; ?>
              <tr>
                <td><input form="<?= e($editFormId) ?>" name="name" type="text" value="<?= e($category['name']) ?>" maxlength="80" required aria-label="Category name"></td>
                <td><input form="<?= e($editFormId) ?>" name="slug" type="text" value="<?= e($category['slug']) ?>" maxlength="100" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" aria-label="Category slug"></td>
                <td>
                  <a class="admin-link" href="<?= e(admin_url('products') . '?category_id=' . rawurlencode((string) $category['id'])) ?>" title="View products in this category">
                    <span class="admin-badge <?= (int) $category['product_count'] > 0 ? 'is-warning' : 'is-neutral' ?>"><?= e((string) $category['product_count']) ?></span>
                  </a>
                  <span class="admin-table__secondary"><?= e((string) $category['active_product_count']) ?> active</span>
                </td>
                <td><?= e(admin_date($category['created_at'], false)) ?></td>
                <td>
                  <div class="admin-row-actions admin-row-actions--wrap">
                    <form id="<?= e($editFormId) ?>" method="post" action="<?= e(admin_url('categories/' . $category['id'] . '/update')) ?>">
                      <?= admin_csrf_field() ?>
                      <button class="admin-button admin-button--primary admin-button--small" type="submit">Save</button>
                    </form>

                    <form method="post" action="<?= e(admin_url('categories/' . $category['id'] . '/delete')) ?>" data-confirm="Delete this category? If it has products, choose a reassignment or confirm unassigning them.">
                      <?= admin_csrf_field() ?>
                      <?php if ((int) $category['product_count'] > 0): ?>
                        <select name="reassign_to" aria-label="Move products to category">
                          <option value="">Unassign products</option>
                          <?php foreach ($allCategories as $other): ?>
                            <?php if ((string) $other['id'] !== (string) $category['id']): ?>
                              <option value="<?= e($other['id']) ?>"><?= e($other['name']) ?></option>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        </select>
                        <label class="admin-check admin-check--danger admin-check--inline">
                          <input type="checkbox" name="confirm_unassign" value="1">
                          Confirm unassign
                        </label>
                      <?php endif; ?>
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
        <nav class="admin-pagination" aria-label="Category pages">
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
</div>

<?php
declare(strict_types=1);
$isEdit = ($mode ?? 'create') === 'edit';
$product = $product ?? [];
$categories = $categories ?? [];
$images = $images ?? [];
$variants = $variants ?? [];
$formError = $formError ?? null;

if ($variants === []) {
    $variants = [
        ['id' => '', 'size' => '', 'color' => '', 'sku' => '', 'stock_quantity' => '', 'sort_order' => 0],
    ];
}

$hasPrimary = false;
foreach ($images as $image) {
    if ((bool) $image['is_primary']) {
        $hasPrimary = true;
        break;
    }
}

$value = static function (string $key, mixed $default = '') use ($product): mixed {
    return array_key_exists($key, $product) ? $product[$key] : $default;
};
?>
<div class="admin-page-head">
  <div>
    <p class="admin-eyebrow"><?= $isEdit ? 'Edit product' : 'New product' ?></p>
    <h2><?= $isEdit ? e((string) $value('name')) : 'Add a product' ?></h2>
    <p class="admin-muted"><?= $isEdit ? 'Update catalogue information, variants and gallery images.' : 'Create a product, then upload and arrange its images.' ?></p>
  </div>
  <div class="admin-page-head__actions">
    <?php if ($isEdit && (bool) $value('is_active', true)): ?>
      <a class="admin-button admin-button--ghost" href="<?= e(site_url('pages/product-details.html?slug=' . rawurlencode((string) $value('slug')))) ?>" target="_blank" rel="noopener">View on storefront</a>
    <?php endif; ?>
    <a class="admin-button admin-button--ghost" href="<?= e(admin_url('products')) ?>">Back to products</a>
  </div>
</div>

<?php if ($formError !== null && $formError !== ''): ?>
  <div class="admin-alert admin-alert--error" role="alert"><?= e($formError) ?></div>
<?php endif; ?>

<form class="admin-form admin-form--wide" method="post" enctype="multipart/form-data"
      action="<?= e($isEdit ? admin_url('products/edit/' . $value('id')) : admin_url('products/create')) ?>">
  <?= admin_csrf_field() ?>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <h3>Product information</h3>
    </div>

    <div class="admin-form-grid">
      <div class="admin-field admin-field--span2">
        <label for="name">Product name</label>
        <input id="name" name="name" type="text" value="<?= e($value('name')) ?>" maxlength="150" required data-slug-source>
      </div>

      <div class="admin-field admin-field--span2">
        <label for="slug">Slug</label>
        <input id="slug" name="slug" type="text" value="<?= e($value('slug')) ?>" maxlength="180" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" data-slug-target>
        <small class="admin-help">Lowercase letters, numbers and hyphens. Leave blank to generate a unique slug from the name.</small>
      </div>

      <div class="admin-field admin-field--span2">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="6" maxlength="20000"><?= e($value('description')) ?></textarea>
      </div>

      <div class="admin-field">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id">
          <option value="">Uncategorised</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= e($category['id']) ?>"<?= (string) $value('category_id') === (string) $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="admin-field">
        <label for="stock_quantity">Stock quantity</label>
        <input id="stock_quantity" name="stock_quantity" type="number" min="0" max="1000000" step="1" value="<?= e($value('stock_quantity', 0)) ?>">
        <small class="admin-help">For products with variants, the total is calculated from variant stock.</small>
      </div>

      <div class="admin-field">
        <label for="price">Regular price (USD)</label>
        <input id="price" name="price" type="number" min="0" max="99999999.99" step="0.01" value="<?= e($value('price')) ?>" required>
      </div>

      <div class="admin-field">
        <label for="sale_price">Sale price (optional)</label>
        <input id="sale_price" name="sale_price" type="number" min="0" max="99999999.99" step="0.01" value="<?= e($value('sale_price')) ?>">
        <small class="admin-help">Must be less than or equal to the regular price.</small>
      </div>

      <div class="admin-field admin-field--check">
        <input type="hidden" name="is_featured" value="0">
        <label for="is_featured">
          <input id="is_featured" name="is_featured" type="checkbox" value="1"<?= (bool) $value('is_featured') ? ' checked' : '' ?>>
          Show as featured on the storefront
        </label>
      </div>

      <div class="admin-field admin-field--check">
        <input type="hidden" name="is_active" value="0">
        <label for="is_active">
          <input id="is_active" name="is_active" type="checkbox" value="1"<?= (bool) $value('is_active', true) ? ' checked' : '' ?>>
          Product is active and publicly visible
        </label>
      </div>
    </div>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h3>Variants</h3>
        <p class="admin-muted">Optional size and colour combinations. When variants exist, the product stock is their sum.</p>
      </div>
      <button class="admin-button admin-button--ghost admin-button--small" type="button" data-add-variant>Add variant</button>
    </div>

    <div class="admin-table-wrap">
      <table class="admin-table admin-table--variants">
        <thead>
          <tr>
            <th scope="col">Size</th>
            <th scope="col">Colour</th>
            <th scope="col">SKU</th>
            <th scope="col">Stock</th>
            <th scope="col">Order</th>
            <th scope="col"><span class="visually-hidden">Remove</span></th>
          </tr>
        </thead>
        <tbody data-variant-body data-next-variant-index="<?= e((string) count($variants)) ?>">
          <?php foreach ($variants as $index => $variant): ?>
            <tr class="admin-variant-row">
              <td>
                <input type="hidden" name="variants[<?= e((string) $index) ?>][id]" value="<?= e($variant['id'] ?? '') ?>">
                <input name="variants[<?= e((string) $index) ?>][size]" type="text" value="<?= e($variant['size'] ?? '') ?>" maxlength="20" aria-label="Variant size">
              </td>
              <td><input name="variants[<?= e((string) $index) ?>][color]" type="text" value="<?= e($variant['color'] ?? '') ?>" maxlength="40" aria-label="Variant colour"></td>
              <td><input name="variants[<?= e((string) $index) ?>][sku]" type="text" value="<?= e($variant['sku'] ?? '') ?>" maxlength="64" aria-label="Variant SKU"></td>
              <td><input name="variants[<?= e((string) $index) ?>][stock_quantity]" type="number" min="0" max="1000000" step="1" value="<?= e($variant['stock_quantity'] ?? '') ?>" aria-label="Variant stock"></td>
              <td><input name="variants[<?= e((string) $index) ?>][sort_order]" type="number" min="0" max="999" step="1" value="<?= e($variant['sort_order'] ?? 0) ?>" aria-label="Variant order"></td>
              <td><button class="admin-button admin-button--ghost admin-button--small" type="button" data-remove-variant>Remove</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <template id="variant-row-template">
      <tr class="admin-variant-row">
        <td>
          <input type="hidden" name="variants[__INDEX__][id]" value="">
          <input name="variants[__INDEX__][size]" type="text" value="" maxlength="20" aria-label="Variant size">
        </td>
        <td><input name="variants[__INDEX__][color]" type="text" value="" maxlength="40" aria-label="Variant colour"></td>
        <td><input name="variants[__INDEX__][sku]" type="text" value="" maxlength="64" aria-label="Variant SKU"></td>
        <td><input name="variants[__INDEX__][stock_quantity]" type="number" min="0" max="1000000" step="1" value="0" aria-label="Variant stock"></td>
        <td><input name="variants[__INDEX__][sort_order]" type="number" min="0" max="999" step="1" value="0" aria-label="Variant order"></td>
        <td><button class="admin-button admin-button--ghost admin-button--small" type="button" data-remove-variant>Remove</button></td>
      </tr>
    </template>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h3>Product images</h3>
        <p class="admin-muted">Images are uploaded to the Supabase Storage <code>product-images</code> bucket. PostgreSQL only stores the object path.</p>
      </div>
    </div>

    <?php if ($images !== []): ?>
      <div class="admin-images">
        <?php foreach ($images as $image): ?>
          <article class="admin-image-card">
            <img src="<?= e(admin_image_url((string) $image['image_url'])) ?>" alt="" width="180" height="225" loading="lazy">

            <div class="admin-image-card__fields">
              <div class="admin-field">
                <label for="alt-<?= e($image['id']) ?>">Alt text</label>
                <input id="alt-<?= e($image['id']) ?>" name="images[<?= e($image['id']) ?>][alt_text]" type="text" value="<?= e($image['alt_text']) ?>" maxlength="255">
              </div>

              <div class="admin-field">
                <label for="sort-<?= e($image['id']) ?>">Order</label>
                <input id="sort-<?= e($image['id']) ?>" name="images[<?= e($image['id']) ?>][sort_order]" type="number" min="0" max="999" step="1" value="<?= e((string) $image['sort_order']) ?>">
              </div>

              <label class="admin-check">
                <input type="radio" name="primary_image" value="<?= e($image['id']) ?>"<?= (bool) $image['is_primary'] ? ' checked' : '' ?>>
                Use as main image
              </label>

              <label class="admin-check admin-check--danger">
                <input type="checkbox" name="remove_images[<?= e($image['id']) ?>]" value="1">
                Remove this image
              </label>

              <div class="admin-field">
                <label for="replace-<?= e($image['id']) ?>">Replace file</label>
                <input id="replace-<?= e($image['id']) ?>" name="replace_image_<?= e($image['id']) ?>" type="file" accept="image/jpeg,image/png,image/webp,image/avif">
              </div>

              <div class="admin-row-actions">
                <button class="admin-button admin-button--ghost admin-button--small" type="button" data-image-move="up">Move up</button>
                <button class="admin-button admin-button--ghost admin-button--small" type="button" data-image-move="down">Move down</button>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if (!$hasPrimary): ?>
        <p class="admin-help">No main image is selected. The first image in the gallery will be used.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="admin-empty-inline">No images uploaded yet. The product will use the placeholder image until you add one.</p>
    <?php endif; ?>

    <div class="admin-form-grid">
      <div class="admin-field admin-field--span2">
        <label for="new-images">Add gallery images</label>
        <input id="new-images" name="new_images[]" type="file" accept="image/jpeg,image/png,image/webp,image/avif" multiple data-image-input>
        <small class="admin-help">Up to 20 images per product, 5 MB each. JPEG, PNG, WebP or AVIF only.</small>
      </div>

      <div class="admin-field admin-field--span2" id="new-primary-wrap" hidden>
        <label for="new_primary_index">Main image</label>
        <select id="new_primary_index" name="new_primary_index">
          <option value="">Keep current main image</option>
        </select>
        <small class="admin-help">Choose one of the new files as the main product image.</small>
      </div>

      <div class="admin-field admin-field--span2">
        <div class="admin-image-previews" data-image-previews aria-live="polite"></div>
      </div>
    </div>
  </section>

  <div class="admin-form-actions">
    <button class="admin-button admin-button--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create product' ?></button>
    <a class="admin-button admin-button--ghost" href="<?= e(admin_url('products')) ?>">Cancel</a>
  </div>
</form>

<?php if ($isEdit): ?>
  <section class="admin-panel admin-panel--danger">
    <div class="admin-panel__head">
      <div>
        <h3>Product status</h3>
        <p class="admin-muted">Deactivate to remove the product from the storefront while keeping its data. Permanent deletion is only allowed when the product has no order history.</p>
      </div>
    </div>
    <div class="admin-row-actions">
      <?php if ((bool) $value('is_active', true)): ?>
        <form method="post" action="<?= e(admin_url('products/delete/' . $value('id'))) ?>" data-confirm="Deactivate this product? It will disappear from the storefront but its data and order history stay intact.">
          <?= admin_csrf_field() ?>
          <input type="hidden" name="mode" value="deactivate">
          <button class="admin-button admin-button--ghost" type="submit">Deactivate product</button>
        </form>
      <?php endif; ?>

      <form method="post" action="<?= e(admin_url('products/delete/' . $value('id'))) ?>" data-confirm="Permanently delete this product and its images? This cannot be undone and is blocked when the product is referenced by an order.">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="mode" value="delete">
        <button class="admin-button admin-button--danger" type="submit">Delete permanently</button>
      </form>
    </div>
  </section>
<?php endif; ?>

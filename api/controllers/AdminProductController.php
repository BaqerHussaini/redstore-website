<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminProduct.php';
require_once __DIR__ . '/../models/AdminCategory.php';
require_once __DIR__ . '/../models/ProductImage.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';
require_once __DIR__ . '/../lib/ImageUploader.php';
require_once __DIR__ . '/../lib/SupabaseStorage.php';

final class AdminProductController
{
    /** @var list<string> */
    public const SORT_KEYS = [
        'newest',
        'oldest',
        'name_asc',
        'name_desc',
        'price_asc',
        'price_desc',
        'stock_asc',
        'stock_desc',
    ];

    private const PLACEHOLDER = 'assets/images/products/placeholder.svg';
    private const MAX_IMAGES = 20;

    private AdminProduct $products;
    private AdminCategory $categories;
    private ProductImage $images;
    private SupabaseStorage $storage;

    public function __construct(?PDO $db = null)
    {
        $db ??= redstore_db();
        $this->products = new AdminProduct($db);
        $this->categories = new AdminCategory($db);
        $this->images = new ProductImage($db);
        $this->storage = new SupabaseStorage();
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function index(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(100, (int) ($query['limit'] ?? 20)));

        $search = trim((string) ($query['search'] ?? ''));
        if (mb_strlen($search) > 120) {
            throw new ApiException(422, 'invalid_search', 'The search term must be 120 characters or fewer.');
        }

        $categoryId = trim((string) ($query['category_id'] ?? ''));
        if ($categoryId !== '' && preg_match('/^[0-9a-f-]{36}$/i', $categoryId) !== 1) {
            throw new ApiException(422, 'invalid_category', 'The category filter is not valid.');
        }

        $status = trim((string) ($query['status'] ?? ''));
        if (!in_array($status, ['', 'active', 'inactive'], true)) {
            throw new ApiException(422, 'invalid_status', 'The status filter is not valid.');
        }

        $featured = trim((string) ($query['featured'] ?? ''));
        if (!in_array($featured, ['', '0', '1'], true)) {
            throw new ApiException(422, 'invalid_featured', 'The featured filter is not valid.');
        }

        $sort = trim((string) ($query['sort'] ?? 'newest'));
        if (!in_array($sort, self::SORT_KEYS, true)) {
            throw new ApiException(422, 'invalid_sort', 'The sort value is not supported.');
        }

        [$rows, $total] = $this->products->paginate([
            'search' => $search,
            'category_id' => $categoryId,
            'status' => $status,
            'featured' => $featured,
            'sort' => $sort,
            'page' => $page,
            'limit' => $limit,
        ]);

        return [
            $rows,
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
                'filters' => [
                    'search' => $search,
                    'category_id' => $categoryId,
                    'status' => $status,
                    'featured' => $featured,
                    'sort' => $sort,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $product = $this->products->find($this->uuidOrFail($id));
        if ($product === null) {
            throw new ApiException(404, 'product_not_found', 'Product not found.');
        }

        $product['images'] = $this->images->forProduct($id);
        $product['variants'] = $this->products->variants($id);

        return $product;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public function create(array $input, array $files): array
    {
        $manageVariants = array_key_exists('variants', $input);
        $variants = $manageVariants ? $this->parseVariants($input['variants']) : [];
        $data = $this->parseProduct($input, null);
        $data['stock_quantity'] = $variants !== []
            ? array_sum(array_column($variants, 'stock_quantity'))
            : $data['stock_quantity'];
        $data['image_url'] = self::PLACEHOLDER;

        $changes = $this->prepareImageChanges($input, $files, [], $data['slug'], $data['name']);
        $uploadedPaths = $changes['uploaded_paths'];

        $db = redstore_db();
        $newPaths = [];

        try {
            $db->beginTransaction();
            $productId = $this->products->create($data);

            if ($variants !== []) {
                $this->products->syncVariants($productId, $variants);
            }

            [$imageUrl, $newPaths] = $this->applyImageChanges($productId, $changes);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->deleteStoragePaths(array_merge($uploadedPaths, $newPaths));
            throw $exception;
        }

        $this->deleteStoragePaths($changes['delete_paths'], false);

        return $this->show($productId);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, array $files): array
    {
        $id = $this->uuidOrFail($id);
        $existing = $this->products->find($id);
        if ($existing === null) {
            throw new ApiException(404, 'product_not_found', 'Product not found.');
        }

        $manageVariants = array_key_exists('variants', $input);
        $variants = $manageVariants ? $this->parseVariants($input['variants']) : null;
        $data = $this->parseProduct($input, $existing);

        if ($variants !== null && $variants !== []) {
            $data['stock_quantity'] = array_sum(array_column($variants, 'stock_quantity'));
        } elseif ($variants !== null && $variants === []) {
            $data['stock_quantity'] = Input::integer($input, 'stock_quantity', 0, 1000000, 0);
        }

        $existingImages = $this->images->forProduct($id);
        $changes = $this->prepareImageChanges($input, $files, $existingImages, $data['slug'], $data['name']);
        $uploadedPaths = $changes['uploaded_paths'];

        $db = redstore_db();
        $newPaths = [];

        try {
            $db->beginTransaction();
            $this->products->update($id, $data);

            if ($variants !== null) {
                $this->products->syncVariants($id, $variants);
            }

            [$imageUrl, $newPaths] = $this->applyImageChanges($id, $changes);
            if ($imageUrl !== null) {
                $this->images->setProductImageUrl($id, $imageUrl);
            }

            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->deleteStoragePaths(array_merge($uploadedPaths, $newPaths));
            throw $exception;
        }

        $this->deleteStoragePaths($changes['delete_paths'], false);

        return $this->show($id);
    }

    public function deactivate(string $id): void
    {
        $this->products->deactivate($this->uuidOrFail($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $id, bool $hard): array
    {
        $id = $this->uuidOrFail($id);
        if (!$hard) {
            $this->products->deactivate($id);
            return ['id' => $id, 'deleted' => false, 'deactivated' => true];
        }

        $result = $this->products->delete($id);
        $this->deleteStoragePaths($result['image_paths'], false);

        return ['id' => $id, 'deleted' => true, 'deactivated' => false];
    }

    public function setStock(string $id, int $stock): int
    {
        return $this->products->setStock($this->uuidOrFail($id), $stock);
    }

    public function setVariantStock(string $variantId, int $stock): int
    {
        return $this->products->setVariantStock($this->uuidOrFail($variantId), $stock);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @return list<array<string, mixed>>
     */
    public function addImages(string $id, array $input, array $files): array
    {
        $id = $this->uuidOrFail($id);
        $product = $this->products->find($id);
        if ($product === null) {
            throw new ApiException(404, 'product_not_found', 'Product not found.');
        }

        $existingImages = $this->images->forProduct($id);
        $changes = $this->prepareImageChanges(
            $input,
            $files,
            $existingImages,
            (string) $product['slug'],
            (string) $product['name']
        );
        $uploadedPaths = $changes['uploaded_paths'];
        $newPaths = [];

        $db = redstore_db();
        try {
            $db->beginTransaction();
            [$imageUrl, $newPaths] = $this->applyImageChanges($id, $changes);
            if ($imageUrl !== null) {
                $this->images->setProductImageUrl($id, $imageUrl);
            }
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->deleteStoragePaths(array_merge($uploadedPaths, $newPaths));
            throw $exception;
        }

        $this->deleteStoragePaths($changes['delete_paths'], false);

        return $this->showProductImages($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function image(string $imageId): array
    {
        $image = $this->images->find($this->uuidOrFail($imageId));
        if ($image === null) {
            throw new ApiException(404, 'image_not_found', 'Image not found.');
        }

        return $image;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateImageMeta(string $imageId, array $input): array
    {
        $image = $this->images->find($this->uuidOrFail($imageId));
        if ($image === null) {
            throw new ApiException(404, 'image_not_found', 'Image not found.');
        }

        $productId = (string) $image['product_id'];
        $alt = $this->cleanText($input['alt_text'] ?? $image['alt_text'], 255);
        $sort = $this->cleanSort($input['sort_order'] ?? $image['sort_order']);
        $isPrimary = Input::boolean($input, 'is_primary');

        $db = redstore_db();
        $db->beginTransaction();
        try {
            $this->images->updateMeta((string) $image['id'], $alt, $sort);

            if ($isPrimary) {
                $this->images->setPrimary($productId, (string) $image['id']);
                $this->images->setProductImageUrl($productId, (string) $image['image_url']);
            }

            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        return $this->showProductImages($productId);
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    public function reorderImages(string $productId, array $input): array
    {
        $productId = $this->uuidOrFail($productId);
        if ($this->products->find($productId) === null) {
            throw new ApiException(404, 'product_not_found', 'Product not found.');
        }

        $ids = $input['image_ids'] ?? null;
        if (!is_array($ids)) {
            throw new ApiException(422, 'invalid_order', 'Provide an ordered list of image ids.');
        }

        $existing = $this->images->forProduct($productId);
        $existingIds = array_map(static fn (array $row): string => (string) $row['id'], $existing);
        $cleanIds = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if (!in_array($id, $existingIds, true)) {
                throw new ApiException(422, 'invalid_order', 'The image order contains an unknown image.');
            }
            $cleanIds[] = $id;
        }
        if (count($cleanIds) !== count($existingIds) || count(array_unique($cleanIds)) !== count($cleanIds)) {
            throw new ApiException(422, 'invalid_order', 'The image order must include every image exactly once.');
        }

        $db = redstore_db();
        $db->beginTransaction();
        try {
            $this->images->shiftSortOrders($productId);
            foreach ($cleanIds as $position => $imageId) {
                $this->images->updateMeta($imageId, (string) $this->findImageAlt($existing, $imageId), $position);
            }
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        return $this->showProductImages($productId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deleteImage(string $imageId): array
    {
        $image = $this->images->find($this->uuidOrFail($imageId));
        if ($image === null) {
            throw new ApiException(404, 'image_not_found', 'Image not found.');
        }

        $productId = (string) $image['product_id'];
        $wasPrimary = (bool) $image['is_primary'];

        $db = redstore_db();
        $db->beginTransaction();
        try {
            $this->images->delete((string) $image['id']);

            $remaining = $this->images->forProduct($productId);
            $newPrimary = null;
            if ($wasPrimary && $remaining !== []) {
                $newPrimary = (string) $remaining[0]['id'];
            } elseif (!$wasPrimary) {
                foreach ($remaining as $row) {
                    if ((bool) $row['is_primary']) {
                        $newPrimary = (string) $row['id'];
                        break;
                    }
                }
            }
            if ($remaining !== []) {
                $this->images->setPrimary($productId, $newPrimary ?? (string) $remaining[0]['id']);
                $primary = $this->images->primaryImage($productId);
                if ($primary !== null) {
                    $this->images->setProductImageUrl($productId, (string) $primary['image_url']);
                }
            } else {
                $this->images->setProductImageUrl($productId, self::PLACEHOLDER);
            }

            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        $this->deleteStoragePaths([(string) $image['image_url']], false);

        return $this->showProductImages($productId);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseProduct(array $input, ?array $existing): array
    {
        $name = (string) Input::string($input, 'name', 150, true);
        $description = Input::string($input, 'description', 20000, false);
        $categoryId = Input::uuid($input, 'category_id', false);
        $price = (string) Input::money($input, 'price', true);
        $salePrice = Input::money($input, 'sale_price', false);

        if ($salePrice !== null && (float) $salePrice > (float) $price) {
            throw new ApiException(422, 'invalid_sale_price', 'The sale price cannot be greater than the regular price.');
        }

        if ($categoryId !== null && $this->categories->find($categoryId) === null) {
            throw new ApiException(422, 'invalid_category', 'The selected category does not exist.');
        }

        $slugInput = trim((string) ($input['slug'] ?? ''));
        if ($slugInput === '') {
            $findId = $existing !== null ? (string) $existing['id'] : null;
            $slug = $this->products->uniqueSlug($name, $findId);
        } else {
            $slug = strtolower($slugInput);
            if (!Input::validSlug($slug)) {
                throw new ApiException(
                    422,
                    'invalid_slug',
                    'The slug may only contain lowercase letters, numbers and single hyphens.'
                );
            }
            $exclude = $existing !== null ? (string) $existing['id'] : null;
            if ($this->products->slugExists($slug, $exclude)) {
                throw new ApiException(409, 'slug_exists', 'Another product already uses that slug.');
            }
        }

        $stock = Input::integer($input, 'stock_quantity', 0, 1000000, 0);
        $imageUrl = $existing !== null && trim((string) $existing['image_url']) !== ''
            ? (string) $existing['image_url']
            : self::PLACEHOLDER;

        $isActive = array_key_exists('is_active', $input)
            ? Input::boolean($input, 'is_active')
            : ($existing === null ? true : (bool) $existing['is_active']);
        $isFeatured = array_key_exists('is_featured', $input)
            ? Input::boolean($input, 'is_featured')
            : ($existing !== null && (bool) $existing['is_featured']);

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'category_id' => $categoryId,
            'price' => $price,
            'sale_price' => $salePrice,
            'stock_quantity' => $stock,
            'image_url' => $imageUrl,
            'is_featured' => $isFeatured,
            'is_active' => $isActive,
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array{id: ?string, size: string, color: string, sku: ?string, stock_quantity: int, sort_order: int}>
     */
    private function parseVariants(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw)) {
            throw new ApiException(422, 'invalid_variants', 'The variants data is not valid.');
        }

        $variants = [];
        foreach ($raw as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $size = trim((string) ($row['size'] ?? ''));
            $color = trim((string) ($row['color'] ?? ''));
            $sku = trim((string) ($row['sku'] ?? ''));
            $stockRaw = $row['stock_quantity'] ?? '';
            $id = trim((string) ($row['id'] ?? ''));

            $isBlankRow = $size === '' && $color === '' && $sku === '' && (string) $stockRaw === '' && $id === '';
            if ($isBlankRow) {
                continue;
            }

            if ($size === '') {
                throw new ApiException(422, 'invalid_variant_size', 'Every variant needs a size.');
            }
            if (mb_strlen($size) > 20) {
                throw new ApiException(422, 'invalid_variant_size', 'Variant sizes must be 20 characters or fewer.');
            }
            if (mb_strlen($color) > 40) {
                throw new ApiException(422, 'invalid_variant_color', 'Variant colours must be 40 characters or fewer.');
            }
            if ($sku !== '' && mb_strlen($sku) > 64) {
                throw new ApiException(422, 'invalid_variant_sku', 'Variant SKUs must be 64 characters or fewer.');
            }
            if ($id !== '' && preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
                throw new ApiException(422, 'invalid_variant', 'One of the variant ids is not valid.');
            }

            $stock = filter_var($stockRaw, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 1000000],
            ]);
            if ($stock === false) {
                throw new ApiException(422, 'invalid_variant_stock', 'Variant stock must be a whole number between 0 and 1,000,000.');
            }

            $sort = $this->cleanSort($row['sort_order'] ?? $index);

            $variants[] = [
                'id' => $id === '' ? null : strtolower($id),
                'size' => $size,
                'color' => $color,
                'sku' => $sku === '' ? null : $sku,
                'stock_quantity' => (int) $stock,
                'sort_order' => $sort,
            ];
        }

        return $variants;
    }

    /**
     * Uploads new/replacement files and prepares the desired image list.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @param list<array<string, mixed>> $existingImages
     * @return array{
     *   existing: list<array<string, mixed>>,
     *   new: list<array<string, mixed>>,
     *   removed_ids: list<string>,
     *   delete_paths: list<string>,
     *   uploaded_paths: list<string>,
     *   primary: array{type: string, value: ?string}
     * }
     */
    private function prepareImageChanges(
        array $input,
        array $files,
        array $existingImages,
        string $slug,
        string $productName
    ): array {
        $uploadedPaths = [];
        $deletePaths = [];
        $newSpecs = [];
        $replacements = [];

        try {
            foreach ($this->normalizeUploads($files['new_images'] ?? null) as $index => $file) {
                $validated = ImageUploader::validate($file);
                $path = ImageUploader::objectPath($slug, 'gallery', $validated['extension']);
                $this->uploadPreparedFile($path, $validated);
                $uploadedPaths[] = $path;

                $altInput = $input['new_alt'][$index] ?? '';
                $newSpecs[] = [
                    'path' => $path,
                    'alt' => $this->cleanText($altInput, 255, $productName),
                    'sort' => 1000 + (int) $index,
                ];
            }

            foreach ($existingImages as $image) {
                $imageId = (string) $image['id'];
                $file = $this->singleUpload($files['replace_image_' . $imageId] ?? null);
                if ($file === null) {
                    continue;
                }

                $validated = ImageUploader::validate($file);
                $path = ImageUploader::objectPath($slug, 'replace', $validated['extension']);
                $this->uploadPreparedFile($path, $validated);
                $uploadedPaths[] = $path;
                $replacements[$imageId] = [
                    'path' => $path,
                    'old' => (string) $image['image_url'],
                ];
            }
        } catch (Throwable $exception) {
            $this->deleteStoragePaths($uploadedPaths);
            throw $exception;
        }

        $removeInput = is_array($input['remove_images'] ?? null) ? $input['remove_images'] : [];
        if (is_array($input['remove_image_ids'] ?? null)) {
            foreach ($input['remove_image_ids'] as $removeId) {
                $removeInput[(string) $removeId] = '1';
            }
        }
        $metaInput = is_array($input['images'] ?? null) ? $input['images'] : [];

        $keptExisting = [];
        $removedIds = [];
        foreach ($existingImages as $index => $image) {
            $imageId = (string) $image['id'];
            if ($this->truthy($removeInput[$imageId] ?? false)) {
                $removedIds[] = $imageId;
                $deletePaths[] = (string) $image['image_url'];
                continue;
            }

            $meta = is_array($metaInput[$imageId] ?? null) ? $metaInput[$imageId] : [];
            $path = $replacements[$imageId]['path'] ?? (string) $image['image_url'];
            if (isset($replacements[$imageId])) {
                $deletePaths[] = $replacements[$imageId]['old'];
            }

            $keptExisting[] = [
                'id' => $imageId,
                'path' => $path,
                'alt' => $this->cleanText($meta['alt_text'] ?? ($image['alt_text'] ?? ''), 255, $productName),
                'sort' => $this->cleanSort($meta['sort_order'] ?? $index),
                'is_primary' => false,
            ];
        }

        $combined = array_merge($keptExisting, $newSpecs);
        if (count($combined) > self::MAX_IMAGES) {
            $this->deleteStoragePaths($uploadedPaths);
            throw new ApiException(422, 'too_many_images', 'A product may have at most ' . self::MAX_IMAGES . ' images.');
        }

        usort($combined, static function (array $a, array $b): int {
            return ($a['sort'] <=> $b['sort']);
        });

        $requestedPrimary = trim((string) ($input['primary_image'] ?? ''));
        $newPrimaryIndex = trim((string) ($input['new_primary_index'] ?? ''));
        $primary = ['type' => 'none', 'value' => null];

        if ($newPrimaryIndex !== '' && ctype_digit($newPrimaryIndex)) {
            $index = (int) $newPrimaryIndex;
            if (!isset($newSpecs[$index])) {
                $this->deleteStoragePaths($uploadedPaths);
                throw new ApiException(422, 'invalid_primary_image', 'The selected new primary image does not exist.');
            }
            $primary = ['type' => 'new', 'value' => (string) $index];
        } elseif ($requestedPrimary !== '') {
            $found = false;
            foreach ($keptExisting as $image) {
                if ($image['id'] === $requestedPrimary) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->deleteStoragePaths($uploadedPaths);
                throw new ApiException(422, 'invalid_primary_image', 'The selected primary image is not part of this product.');
            }
            $primary = ['type' => 'existing', 'value' => $requestedPrimary];
        } else {
            foreach ($keptExisting as $image) {
                $source = null;
                foreach ($existingImages as $existingImage) {
                    if ((string) $existingImage['id'] === $image['id']) {
                        $source = $existingImage;
                        break;
                    }
                }
                if ($source !== null && (bool) $source['is_primary']) {
                    $primary = ['type' => 'existing', 'value' => $image['id']];
                    break;
                }
            }
        }

        foreach ($combined as $position => &$image) {
            $image['sort'] = $position;
        }
        unset($image);

        return [
            'existing' => $keptExisting,
            'new' => $newSpecs,
            'removed_ids' => $removedIds,
            'delete_paths' => array_values(array_unique($deletePaths)),
            'uploaded_paths' => $uploadedPaths,
            'primary' => $primary,
            'combined' => $combined,
        ];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array{0: ?string, 1: list<string>}
     */
    private function applyImageChanges(string $productId, array $changes): array
    {
        $existing = $changes['existing'];
        $new = $changes['new'];
        $removedIds = $changes['removed_ids'];
        $combined = $changes['combined'];
        $newPaths = [];
        $newIdsByIndex = [];

        if ($combined === [] && $removedIds === []) {
            return [null, []];
        }

        try {
            $this->images->shiftSortOrders($productId);
        } catch (PDOException $exception) {
            error_log('[RedStore Admin] Could not shift image order: ' . $exception->getMessage());
            throw new ApiException(500, 'image_order_failed', 'The image order could not be updated.');
        }

        $newIndex = 0;
        foreach ($new as $spec) {
            $temporaryOrder = 20000 + $newIndex;
            $id = $this->images->insert($productId, $spec['path'], $spec['alt'], $temporaryOrder, false);
            $newIdsByIndex[$newIndex] = $id;
            $newPaths[] = $spec['path'];
            $newIndex++;
        }

        $newIndex = 0;
        foreach ($combined as $image) {
            if (isset($image['id'])) {
                $this->images->update(
                    (string) $image['id'],
                    (string) $image['path'],
                    (string) $image['alt'],
                    (int) $image['sort'],
                    false
                );
            } else {
                $id = $newIdsByIndex[$newIndex] ?? null;
                if ($id === null) {
                    throw new ApiException(500, 'image_order_failed', 'A new image could not be saved.');
                }
                $this->images->update($id, (string) $image['path'], (string) $image['alt'], (int) $image['sort'], false);
                $newIndex++;
            }
        }

        if ($removedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
            $delete = redstore_db()->prepare(
                "DELETE FROM product_images WHERE product_id = ? AND id IN ($placeholders)"
            );
            $delete->execute(array_merge([$productId], $removedIds));
        }

        $primary = $changes['primary'];
        $primaryImageId = null;
        if ($primary['type'] === 'existing' && is_string($primary['value'])) {
            $primaryImageId = $primary['value'];
        } elseif ($primary['type'] === 'new' && $primary['value'] !== null) {
            $primaryImageId = $newIdsByIndex[(int) $primary['value']] ?? null;
        } elseif ($combined !== []) {
            $primaryImageId = isset($combined[0]['id']) ? (string) $combined[0]['id'] : ($newIdsByIndex[0] ?? null);
        }

        $this->images->setPrimary($productId, $primaryImageId);

        $primaryImage = $this->images->primaryImage($productId);
        $imageUrl = $primaryImage !== null ? (string) $primaryImage['image_url'] : self::PLACEHOLDER;
        $this->images->setProductImageUrl($productId, $imageUrl);

        return [$imageUrl, $newPaths];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function showProductImages(string $productId): array
    {
        return $this->images->forProduct($productId);
    }

    /**
     * @param list<array<string, mixed>> $existing
     */
    private function findImageAlt(array $existing, string $imageId): string
    {
        foreach ($existing as $image) {
            if ((string) $image['id'] === $imageId) {
                return (string) $image['alt_text'];
            }
        }

        return '';
    }

    /**
     * @param array{tmp_name: string, mime: string, extension: string, size: int} $validated
     */
    private function uploadPreparedFile(string $path, array $validated): void
    {
        $contents = file_get_contents($validated['tmp_name']);
        if ($contents === false) {
            throw new ApiException(422, 'upload_read_failed', 'The uploaded image could not be read.');
        }

        $result = $this->storage->upload($path, $contents, $validated['mime']);
        if (!$result['ok']) {
            error_log('[RedStore Storage] Upload failed with status ' . $result['status'] . ': ' . $result['body']);
            throw new ApiException(502, 'storage_upload_failed', 'The image could not be uploaded to Supabase Storage.');
        }
    }

    /**
     * @param list<string> $paths
     */
    private function deleteStoragePaths(array $paths, bool $logErrors = true): void
    {
        foreach (array_unique($paths) as $path) {
            $objectPath = $this->storage->objectPathFromStoredValue($path);
            if ($objectPath === null) {
                continue;
            }
            if (!$this->storage->isConfigured()) {
                continue;
            }

            $result = $this->storage->delete($objectPath);
            if (!$result['ok'] && $logErrors && $result['status'] !== 404) {
                error_log('[RedStore Storage] Delete failed for ' . $objectPath . ' (status ' . $result['status'] . ')');
            }
        }
    }

    /**
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    private function normalizeUploads(mixed $entry): array
    {
        return ImageUploader::normalize($entry);
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    private function singleUpload(mixed $entry): ?array
    {
        $files = ImageUploader::normalize($entry);
        if ($files === []) {
            return null;
        }

        return $files[0]['error'] === UPLOAD_ERR_NO_FILE ? null : $files[0];
    }

    private function cleanText(mixed $value, int $max, string $default = ''): string
    {
        if (is_array($value)) {
            return $default;
        }
        $value = trim(str_replace("\0", '', (string) $value));
        if ($value === '') {
            return $default;
        }

        return mb_substr($value, 0, $max);
    }

    private function cleanSort(mixed $value): int
    {
        if (is_array($value)) {
            return 0;
        }
        $filtered = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 999],
        ]);

        return $filtered === false ? 0 : (int) $filtered;
    }

    private function truthy(mixed $value): bool
    {
        if (is_array($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function uuidOrFail(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) !== 1) {
            throw new ApiException(404, 'product_not_found', 'Product not found.');
        }

        return strtolower($id);
    }
}

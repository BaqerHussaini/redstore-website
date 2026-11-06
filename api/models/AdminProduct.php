<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';

final class AdminProduct
{
    public const LOW_STOCK_THRESHOLD = 10;

    /** @var array<string, string> */
    private const SORTS = [
        'newest' => 'p.created_at DESC, p.name ASC',
        'oldest' => 'p.created_at ASC, p.name ASC',
        'name_asc' => 'p.name ASC',
        'name_desc' => 'p.name DESC',
        'price_asc' => 'p.price ASC, p.name ASC',
        'price_desc' => 'p.price DESC, p.name ASC',
        'stock_asc' => 'p.stock_quantity ASC, p.name ASC',
        'stock_desc' => 'p.stock_quantity DESC, p.name ASC',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function paginate(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $sort = self::SORTS[(string) ($filters['sort'] ?? 'newest')] ?? self::SORTS['newest'];
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $countSql = 'SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE ' . $where;
        $countStatement = $this->db->prepare($countSql);
        $this->bindParams($countStatement, $params);
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

        $sql = <<<'SQL'
            SELECT
                p.id,
                p.name,
                p.slug,
                p.description,
                p.price,
                p.sale_price,
                p.stock_quantity,
                p.image_url,
                p.is_featured,
                p.is_active,
                p.created_at,
                p.updated_at,
                c.id AS category_id,
                c.name AS category_name,
                c.slug AS category_slug,
                img.image_url AS main_image_url,
                img.alt_text AS main_image_alt,
                (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id) AS variant_count
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN LATERAL (
                SELECT pi.image_url, pi.alt_text
                FROM product_images pi
                WHERE pi.product_id = p.id
                ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.created_at ASC
                LIMIT 1
            ) img ON true
            WHERE %s
            ORDER BY %s
            LIMIT :limit OFFSET :offset
            SQL;

        $statement = $this->db->prepare(sprintf($sql, $where, $sort));
        $this->bindParams($statement, $params);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [$statement->fetchAll() ?: [], $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function slugExists(string $slug, ?string $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM products WHERE slug = :slug';
        $params = [':slug' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $statement = $this->db->prepare($sql);
        $this->bindParams($statement, $params);
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    public function uniqueSlug(string $base, ?string $excludeId = null): string
    {
        $base = Input::slugify($base);
        $slug = $base;
        $suffix = 2;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = substr($base, 0, 170) . '-' . $suffix;
            $suffix++;
            if ($suffix > 1000) {
                throw new ApiException(409, 'slug_conflict', 'A unique product slug could not be generated.');
            }
        }

        return $slug;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        $statement = $this->db->prepare(
            'INSERT INTO products
                (category_id, name, slug, description, price, sale_price,
                 stock_quantity, image_url, is_featured, is_active)
             VALUES
                (:category_id, :name, :slug, :description, :price, :sale_price,
                 :stock_quantity, :image_url, :is_featured, :is_active)
             RETURNING id'
        );
        $this->bindProduct($statement, $data);
        $statement->execute();

        return (string) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): void
    {
        $statement = $this->db->prepare(
            'UPDATE products SET
                category_id = :category_id,
                name = :name,
                slug = :slug,
                description = :description,
                price = :price,
                sale_price = :sale_price,
                stock_quantity = :stock_quantity,
                image_url = :image_url,
                is_featured = :is_featured,
                is_active = :is_active
             WHERE id = :id'
        );
        $this->bindProduct($statement, $data);
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        if ($statement->rowCount() < 1) {
            throw new ApiException(404, 'product_not_found', 'Product not found.');
        }
    }

    public function deactivate(string $id): void
    {
        $statement = $this->db->prepare('UPDATE products SET is_active = false WHERE id = :id');
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        if ($statement->rowCount() < 1) {
            throw new ApiException(404, 'product_not_found', 'Product not found.');
        }
    }

    /**
     * @return array{image_paths: list<string>}
     */
    public function delete(string $id): array
    {
        $orderStatement = $this->db->prepare('SELECT COUNT(*) FROM order_items WHERE product_id = :id');
        $orderStatement->bindValue(':id', $id, PDO::PARAM_STR);
        $orderStatement->execute();
        if ((int) $orderStatement->fetchColumn() > 0) {
            throw new ApiException(
                409,
                'product_has_orders',
                'This product appears in an order. Deactivate it instead of deleting it to preserve order history.'
            );
        }

        $product = $this->find($id);
        if ($product === null) {
            throw new ApiException(404, 'product_not_found', 'Product not found.');
        }

        $paths = [];
        if (trim((string) $product['image_url']) !== '') {
            $paths[] = (string) $product['image_url'];
        }

        $imageStatement = $this->db->prepare('SELECT image_url FROM product_images WHERE product_id = :id');
        $imageStatement->bindValue(':id', $id, PDO::PARAM_STR);
        $imageStatement->execute();
        foreach ($imageStatement->fetchAll() ?: [] as $row) {
            $paths[] = (string) $row['image_url'];
        }

        $delete = $this->db->prepare('DELETE FROM products WHERE id = :id');
        $delete->bindValue(':id', $id, PDO::PARAM_STR);
        $delete->execute();

        return ['image_paths' => array_values(array_unique($paths))];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function variants(string $productId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, product_id, size, color, sku, stock_quantity, sort_order
             FROM product_variants
             WHERE product_id = :product_id
             ORDER BY sort_order ASC, size ASC, color ASC'
        );
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    public function variantCount(string $productId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id = :product_id');
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Synchronise the submitted variant rows with the database. Existing rows
     * keep their ids; removed rows are deleted.
     *
     * @param list<array{id: ?string, size: string, color: string, sku: ?string, stock_quantity: int, sort_order: int}> $variants
     * @return int aggregate stock
     */
    public function syncVariants(string $productId, array $variants): int
    {
        $seenCombinations = [];
        $seenSkus = [];
        foreach ($variants as $variant) {
            $key = mb_strtolower($variant['size'] . "\0" . $variant['color']);
            if (isset($seenCombinations[$key])) {
                throw new ApiException(
                    422,
                    'duplicate_variant',
                    'Two variants have the same size and colour. Combine them into one row.'
                );
            }
            $seenCombinations[$key] = true;

            if ($variant['sku'] !== null && $variant['sku'] !== '') {
                $skuKey = mb_strtolower($variant['sku']);
                if (isset($seenSkus[$skuKey])) {
                    throw new ApiException(422, 'duplicate_sku', 'Two variants use the same SKU.');
                }
                $seenSkus[$skuKey] = true;
            }
        }

        $existing = $this->variants($productId);
        $existingIds = array_map(static fn (array $row): string => (string) $row['id'], $existing);
        $submittedIds = [];

        try {
            foreach ($variants as $variant) {
                if ($variant['id'] !== null && $variant['id'] !== '') {
                    if (!in_array($variant['id'], $existingIds, true)) {
                        throw new ApiException(422, 'invalid_variant', 'One of the variants does not belong to this product.');
                    }
                    $submittedIds[] = $variant['id'];

                    $statement = $this->db->prepare(
                        'UPDATE product_variants
                         SET size = :size, color = :color, sku = :sku,
                             stock_quantity = :stock_quantity, sort_order = :sort_order
                         WHERE id = :id AND product_id = :product_id'
                    );
                    $statement->bindValue(':size', $variant['size'], PDO::PARAM_STR);
                    $statement->bindValue(':color', $variant['color'], PDO::PARAM_STR);
                    $this->bindNullableString($statement, ':sku', $variant['sku']);
                    $statement->bindValue(':stock_quantity', $variant['stock_quantity'], PDO::PARAM_INT);
                    $statement->bindValue(':sort_order', $variant['sort_order'], PDO::PARAM_INT);
                    $statement->bindValue(':id', $variant['id'], PDO::PARAM_STR);
                    $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
                    $statement->execute();
                } else {
                    $statement = $this->db->prepare(
                        'INSERT INTO product_variants (product_id, size, color, sku, stock_quantity, sort_order)
                         VALUES (:product_id, :size, :color, :sku, :stock_quantity, :sort_order)'
                    );
                    $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
                    $statement->bindValue(':size', $variant['size'], PDO::PARAM_STR);
                    $statement->bindValue(':color', $variant['color'], PDO::PARAM_STR);
                    $this->bindNullableString($statement, ':sku', $variant['sku']);
                    $statement->bindValue(':stock_quantity', $variant['stock_quantity'], PDO::PARAM_INT);
                    $statement->bindValue(':sort_order', $variant['sort_order'], PDO::PARAM_INT);
                    $statement->execute();
                }
            }

            $toDelete = array_values(array_diff($existingIds, $submittedIds));
            if ($toDelete !== []) {
                $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                $delete = $this->db->prepare(
                    "DELETE FROM product_variants WHERE product_id = ? AND id IN ($placeholders)"
                );
                $delete->execute(array_merge([$productId], $toDelete));
            }
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23505', '23503', '23514'], true)) {
                throw new ApiException(
                    409,
                    'variant_conflict',
                    'The variants conflict with existing sizes, colours or SKUs. Check for duplicates and try again.'
                );
            }
            throw $exception;
        }

        return $this->recalculateAggregateStock($productId);
    }

    public function recalculateAggregateStock(string $productId): int
    {
        $statement = $this->db->prepare(
            'UPDATE products p
             SET stock_quantity = COALESCE((
                 SELECT SUM(pv.stock_quantity) FROM product_variants pv WHERE pv.product_id = p.id
             ), p.stock_quantity)
             WHERE p.id = :id
             RETURNING p.stock_quantity'
        );
        $statement->bindValue(':id', $productId, PDO::PARAM_STR);
        $statement->execute();
        $value = $statement->fetchColumn();

        return (int) ($value === false ? 0 : $value);
    }

    public function setStock(string $productId, int $stock): int
    {
        if ($this->variantCount($productId) > 0) {
            throw new ApiException(
                422,
                'stock_managed_by_variants',
                'This product uses size/colour variants. Update variant stock instead.'
            );
        }

        $statement = $this->db->prepare(
            'UPDATE products SET stock_quantity = :stock WHERE id = :id RETURNING stock_quantity'
        );
        $statement->bindValue(':stock', $stock, PDO::PARAM_INT);
        $statement->bindValue(':id', $productId, PDO::PARAM_STR);
        $statement->execute();
        $value = $statement->fetchColumn();

        if ($value === false) {
            throw new ApiException(404, 'product_not_found', 'Product not found.');
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findVariant(string $variantId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, product_id, size, color, sku, stock_quantity, sort_order
             FROM product_variants WHERE id = :id LIMIT 1'
        );
        $statement->bindValue(':id', $variantId, PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function setVariantStock(string $variantId, int $stock): int
    {
        $variant = $this->findVariant($variantId);
        if ($variant === null) {
            throw new ApiException(404, 'variant_not_found', 'Variant not found.');
        }

        $statement = $this->db->prepare(
            'UPDATE product_variants SET stock_quantity = :stock WHERE id = :id'
        );
        $statement->bindValue(':stock', $stock, PDO::PARAM_INT);
        $statement->bindValue(':id', $variantId, PDO::PARAM_STR);
        $statement->execute();

        return $this->recalculateAggregateStock((string) $variant['product_id']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lowStock(int $limit = 10, int $threshold = self::LOW_STOCK_THRESHOLD): array
    {
        $statement = $this->db->prepare(
            'SELECT
                p.id, p.name, p.slug, p.stock_quantity, p.image_url, p.is_active,
                c.name AS category_name,
                img.image_url AS main_image_url,
                img.alt_text AS main_image_alt
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN LATERAL (
                SELECT pi.image_url, pi.alt_text
                FROM product_images pi
                WHERE pi.product_id = p.id
                ORDER BY pi.is_primary DESC, pi.sort_order ASC
                LIMIT 1
             ) img ON true
             WHERE p.stock_quantity <= :threshold
             ORDER BY p.stock_quantity ASC, p.name ASC
             LIMIT :limit'
        );
        $statement->bindValue(':threshold', $threshold, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 6): array
    {
        $statement = $this->db->prepare(
            'SELECT
                p.id, p.name, p.slug, p.price, p.sale_price, p.stock_quantity,
                p.image_url, p.is_active, p.created_at,
                c.name AS category_name,
                img.image_url AS main_image_url
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN LATERAL (
                SELECT pi.image_url
                FROM product_images pi
                WHERE pi.product_id = p.id
                ORDER BY pi.is_primary DESC, pi.sort_order ASC
                LIMIT 1
             ) img ON true
             ORDER BY p.created_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, min(20, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = ['1 = 1'];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $conditions[] = '(p.name ILIKE :search OR p.slug ILIKE :search OR p.description ILIKE :search)';
            $params[':search'] = $term;
        }

        $categoryId = trim((string) ($filters['category_id'] ?? ''));
        if ($categoryId !== '') {
            $conditions[] = 'p.category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === 'active') {
            $conditions[] = 'p.is_active = true';
        } elseif ($status === 'inactive') {
            $conditions[] = 'p.is_active = false';
        }

        $featured = trim((string) ($filters['featured'] ?? ''));
        if ($featured === '1') {
            $conditions[] = 'p.is_featured = true';
        } elseif ($featured === '0') {
            $conditions[] = 'p.is_featured = false';
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, string> $params
     */
    private function bindParams(PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function bindProduct(PDOStatement $statement, array $data): void
    {
        if ($data['category_id'] === null) {
            $statement->bindValue(':category_id', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':category_id', $data['category_id'], PDO::PARAM_STR);
        }
        $statement->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $statement->bindValue(':slug', $data['slug'], PDO::PARAM_STR);
        $this->bindNullableString($statement, ':description', $data['description']);
        $statement->bindValue(':price', $data['price'], PDO::PARAM_STR);
        $this->bindNullableString($statement, ':sale_price', $data['sale_price']);
        $statement->bindValue(':stock_quantity', $data['stock_quantity'], PDO::PARAM_INT);
        $statement->bindValue(':image_url', $data['image_url'], PDO::PARAM_STR);
        $statement->bindValue(':is_featured', $data['is_featured'], PDO::PARAM_BOOL);
        $statement->bindValue(':is_active', $data['is_active'], PDO::PARAM_BOOL);
    }

    private function bindNullableString(PDOStatement $statement, string $placeholder, mixed $value): void
    {
        if ($value === null || $value === '') {
            $statement->bindValue($placeholder, null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
        }
    }
}

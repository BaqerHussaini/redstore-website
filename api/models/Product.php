<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ImageUrl.php';

final class Product
{
    /** @var array<string, string> */
    private const SORTS = [
        'default' => 'p.is_featured DESC, p.created_at DESC, p.name ASC',
        'featured' => 'p.is_featured DESC, p.created_at DESC, p.name ASC',
        'newest' => 'p.created_at DESC, p.name ASC',
        'price_asc' => 'p.price ASC, p.name ASC',
        'price_desc' => 'p.price DESC, p.name ASC',
        'name_asc' => 'p.name ASC',
        'name_desc' => 'p.name DESC',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{category?: ?string, search?: ?string, featured_only?: bool, sort?: string, limit?: int, offset?: int} $filters
     * @return list<array<string, mixed>>
     */
    public function findActive(array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $sort = self::SORTS[$filters['sort'] ?? 'default'] ?? self::SORTS['default'];
        $limit = max(1, min(60, (int) ($filters['limit'] ?? 12)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $sql = $this->baseSelect()
            . ' WHERE ' . $where
            . ' ORDER BY ' . $sort
            . ' LIMIT :limit OFFSET :offset';

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll() ?: [];
        return $this->hydrate($rows);
    }

    /**
     * @param array{category?: ?string, search?: ?string, featured_only?: bool} $filters
     */
    public function countActive(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = 'SELECT COUNT(*) AS total
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE ' . $where;

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->execute();

        return (int) ($statement->fetchColumn() ?: 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveBySlug(string $slug): ?array
    {
        $sql = $this->baseSelect() . ' WHERE p.is_active = true AND p.slug = :slug LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->bindValue(':slug', $slug, PDO::PARAM_STR);
        $statement->execute();

        $rows = $statement->fetchAll() ?: [];
        $products = $this->hydrate($rows);

        return $products[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findFeatured(int $limit = 8): array
    {
        return $this->findActive([
            'featured_only' => true,
            'sort' => 'featured',
            'limit' => $limit,
            'offset' => 0,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findLatest(int $limit = 8): array
    {
        return $this->findActive([
            'sort' => 'newest',
            'limit' => $limit,
            'offset' => 0,
        ]);
    }

    /**
     * @param array{category?: ?string, search?: ?string, featured_only?: bool} $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = ['p.is_active = true'];
        $params = [];

        if (!empty($filters['featured_only'])) {
            $conditions[] = 'p.is_featured = true';
        }

        if (!empty($filters['category'])) {
            $conditions[] = 'c.slug = :category';
            $params[':category'] = (string) $filters['category'];
        }

        if (!empty($filters['search'])) {
            $term = $this->likeTerm((string) $filters['search']);
            $conditions[] = '(p.name ILIKE :search_name OR p.description ILIKE :search_description)';
            $params[':search_name'] = $term;
            $params[':search_description'] = $term;
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function likeTerm(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        return '%' . $escaped . '%';
    }

    private function baseSelect(): string
    {
        return <<<'SQL'
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
                img.alt_text AS main_image_alt
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN LATERAL (
                SELECT pi.image_url, pi.alt_text
                FROM product_images pi
                WHERE pi.product_id = p.id
                ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.created_at ASC
                LIMIT 1
            ) img ON true
            SQL;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_values(array_map(static fn (array $row): string => (string) $row['id'], $rows));
        $images = $this->fetchImages($ids);
        $variants = $this->fetchVariants($ids);

        $products = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $productImages = $images[$id] ?? [];
            $productVariants = $variants[$id] ?? [];

            $gallery = [];
            foreach ($productImages as $image) {
                $url = ImageUrl::resolve($image['image_url'] ?? null);
                if ($url === null) {
                    continue;
                }
                $gallery[] = [
                    'url' => $url,
                    'alt' => (string) ($image['alt_text'] ?? ''),
                    'is_primary' => (bool) $image['is_primary'],
                    'sort_order' => (int) $image['sort_order'],
                ];
            }

            $mainPath = $row['main_image_url'] ?? null;
            if ($mainPath === null || trim((string) $mainPath) === '') {
                $mainPath = $row['image_url'] ?? null;
            }
            $mainUrl = ImageUrl::resolve($mainPath !== null ? (string) $mainPath : null);

            if ($gallery === [] && $mainUrl !== null) {
                $gallery[] = [
                    'url' => $mainUrl,
                    'alt' => (string) ($row['main_image_alt'] ?? $row['name']),
                    'is_primary' => true,
                    'sort_order' => 0,
                ];
            }

            $price = (float) $row['price'];
            $salePrice = $row['sale_price'] !== null ? (float) $row['sale_price'] : null;
            $stock = (int) $row['stock_quantity'];

            $products[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
                'price' => $price,
                'sale_price' => $salePrice,
                'effective_price' => $salePrice ?? $price,
                'currency' => 'USD',
                'category' => $row['category_id'] !== null ? [
                    'id' => (string) $row['category_id'],
                    'name' => (string) $row['category_name'],
                    'slug' => (string) $row['category_slug'],
                ] : null,
                'main_image' => $mainUrl !== null ? [
                    'url' => $mainUrl,
                    'alt' => (string) ($row['main_image_alt'] ?? $row['name']),
                ] : null,
                'gallery' => $gallery,
                'variants' => $productVariants,
                'stock_quantity' => $stock,
                'in_stock' => $stock > 0,
                'is_featured' => (bool) $row['is_featured'],
                'created_at' => $this->formatDate($row['created_at'] ?? null),
                'updated_at' => $this->formatDate($row['updated_at'] ?? null),
            ];
        }

        return $products;
    }

    /**
     * @param list<string> $ids
     * @return array<string, list<array<string, mixed>>>
     */
    private function fetchImages(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT product_id, image_url, alt_text, sort_order, is_primary
                FROM product_images
                WHERE product_id IN ($placeholders)
                ORDER BY product_id ASC, sort_order ASC, is_primary DESC";

        $statement = $this->db->prepare($sql);
        $statement->execute($ids);

        $grouped = [];
        foreach ($statement->fetchAll() ?: [] as $row) {
            $grouped[(string) $row['product_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param list<string> $ids
     * @return array<string, list<array<string, mixed>>>
     */
    private function fetchVariants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, product_id, size, color, sku, stock_quantity, sort_order
                FROM product_variants
                WHERE product_id IN ($placeholders)
                ORDER BY product_id ASC, sort_order ASC, size ASC, color ASC";

        $statement = $this->db->prepare($sql);
        $statement->execute($ids);

        $grouped = [];
        foreach ($statement->fetchAll() ?: [] as $row) {
            $stock = (int) $row['stock_quantity'];
            $grouped[(string) $row['product_id']][] = [
                'id' => (string) $row['id'],
                'size' => (string) $row['size'],
                'color' => (string) ($row['color'] ?? ''),
                'sku' => $row['sku'] !== null ? (string) $row['sku'] : null,
                'stock_quantity' => $stock,
                'in_stock' => $stock > 0,
                'sort_order' => (int) $row['sort_order'],
            ];
        }

        return $grouped;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable((string) $value))->format(DATE_ATOM);
        } catch (Exception) {
            return null;
        }
    }
}

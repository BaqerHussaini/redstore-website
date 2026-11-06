<?php
declare(strict_types=1);

final class AdminInventory
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function paginate(array $filters): array
    {
        $conditions = ['1 = 1'];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $conditions[] = '(p.name ILIKE :search OR p.slug ILIKE :search)';
            $params[':search'] = $term;
        }

        $stockStatus = trim((string) ($filters['stock_status'] ?? ''));
        $threshold = (int) ($filters['threshold'] ?? 10);
        if ($stockStatus === 'out') {
            $conditions[] = 'p.stock_quantity = 0';
        } elseif ($stockStatus === 'low') {
            $conditions[] = 'p.stock_quantity > 0 AND p.stock_quantity <= :threshold';
            $params[':threshold'] = $threshold;
        } elseif ($stockStatus === 'in') {
            $conditions[] = 'p.stock_quantity > :threshold';
            $params[':threshold'] = $threshold;
        } elseif (!empty($filters['low_stock'])) {
            $conditions[] = 'p.stock_quantity <= :threshold';
            $params[':threshold'] = $threshold;
        }

        $where = implode(' AND ', $conditions);
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $count = $this->db->prepare('SELECT COUNT(*) FROM products p WHERE ' . $where);
        foreach ($params as $key => $value) {
            $count->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $count->execute();
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT
                    p.id, p.name, p.slug, p.stock_quantity, p.is_active, p.image_url,
                    c.name AS category_name,
                    (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id) AS variant_count,
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
                WHERE ' . $where . '
                ORDER BY p.stock_quantity ASC, p.name ASC
                LIMIT :limit OFFSET :offset';

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [$statement->fetchAll() ?: [], $total];
    }
}

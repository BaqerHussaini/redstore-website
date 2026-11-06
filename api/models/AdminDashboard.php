<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminOrder.php';

final class AdminDashboard
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<string, int|float>
     */
    public function stats(int $lowStockThreshold): array
    {
        $sql = <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM products) AS total_products,
                (SELECT COUNT(*) FROM products WHERE is_active = true) AS active_products,
                (SELECT COUNT(*) FROM categories) AS total_categories,
                (SELECT COUNT(*) FROM orders) AS total_orders,
                (SELECT COUNT(*) FROM orders WHERE status = 'pending') AS pending_orders,
                (SELECT COUNT(*) FROM users WHERE role = 'user') AS total_customers,
                (SELECT COUNT(*) FROM products WHERE stock_quantity <= :threshold) AS low_stock_products,
                (SELECT COALESCE(SUM(total_amount), 0) FROM orders
                    WHERE status IN ('paid', 'processing', 'shipped', 'delivered')) AS sales_total,
                (SELECT COALESCE(SUM(total_amount), 0) FROM orders
                    WHERE status IN ('paid', 'processing', 'shipped', 'delivered')
                      AND created_at >= date_trunc('month', now())) AS sales_this_month,
                (SELECT COUNT(*) FROM orders WHERE created_at >= date_trunc('month', now())) AS orders_this_month
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':threshold', $lowStockThreshold, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch() ?: [];

        return [
            'total_products' => (int) ($row['total_products'] ?? 0),
            'active_products' => (int) ($row['active_products'] ?? 0),
            'total_categories' => (int) ($row['total_categories'] ?? 0),
            'total_orders' => (int) ($row['total_orders'] ?? 0),
            'pending_orders' => (int) ($row['pending_orders'] ?? 0),
            'total_customers' => (int) ($row['total_customers'] ?? 0),
            'low_stock_products' => (int) ($row['low_stock_products'] ?? 0),
            'sales_total' => (float) ($row['sales_total'] ?? 0),
            'sales_this_month' => (float) ($row['sales_this_month'] ?? 0),
            'orders_this_month' => (int) ($row['orders_this_month'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bestSellers(int $limit = 6): array
    {
        $statement = $this->db->prepare(
            'SELECT
                oi.product_id,
                MAX(oi.product_name) AS product_name,
                SUM(oi.quantity) AS units_sold,
                SUM(oi.line_total) AS revenue,
                p.slug,
                img.image_url AS main_image_url
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             LEFT JOIN products p ON p.id = oi.product_id
             LEFT JOIN LATERAL (
                SELECT pi.image_url
                FROM product_images pi
                WHERE pi.product_id = oi.product_id
                ORDER BY pi.is_primary DESC, pi.sort_order ASC
                LIMIT 1
             ) img ON true
             WHERE oi.product_id IS NOT NULL
               AND o.status NOT IN (\'cancelled\', \'refunded\')
             GROUP BY oi.product_id, p.slug, img.image_url
             ORDER BY units_sold DESC, revenue DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, min(20, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<string, int>
     */
    public function orderStatusCounts(): array
    {
        $rows = $this->db->query(
            'SELECT status, COUNT(*) AS total FROM orders GROUP BY status ORDER BY status ASC'
        )->fetchAll() ?: [];

        $counts = [];
        foreach (AdminOrder::STATUSES as $status) {
            $counts[$status] = 0;
        }
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}

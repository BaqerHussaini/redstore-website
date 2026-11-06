<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ApiException.php';

final class AdminOrder
{
    /** @var list<string> */
    public const STATUSES = ['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'pending' => ['paid', 'processing', 'cancelled'],
        'paid' => ['processing', 'shipped', 'cancelled', 'refunded'],
        'processing' => ['shipped', 'delivered', 'cancelled', 'refunded'],
        'shipped' => ['delivered', 'refunded'],
        'delivered' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    /** @var array<string, string> */
    private const SORTS = [
        'newest' => 'o.created_at DESC, o.id DESC',
        'oldest' => 'o.created_at ASC, o.id ASC',
        'total_desc' => 'o.total_amount DESC, o.created_at DESC',
        'total_asc' => 'o.total_amount ASC, o.created_at DESC',
        'updated' => 'o.updated_at DESC, o.created_at DESC',
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

        $count = $this->db->prepare(
            'SELECT COUNT(DISTINCT o.id)
             FROM orders o
             JOIN users u ON u.id = o.user_id
             WHERE ' . $where
        );
        $this->bindParams($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT
                    o.id, o.order_number, o.status, o.subtotal, o.shipping_fee,
                    o.total_amount, o.currency, o.shipping_full_name, o.shipping_email,
                    o.created_at, o.updated_at,
                    u.full_name AS customer_name, u.email AS customer_email,
                    COUNT(oi.id) AS item_count
                FROM orders o
                JOIN users u ON u.id = o.user_id
                LEFT JOIN order_items oi ON oi.order_id = o.id
                WHERE ' . $where . '
                GROUP BY o.id, u.full_name, u.email
                ORDER BY ' . $sort . '
                LIMIT :limit OFFSET :offset';

        $statement = $this->db->prepare($sql);
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
            'SELECT o.*, u.full_name AS customer_name, u.email AS customer_email, u.is_active AS customer_active
             FROM orders o
             JOIN users u ON u.id = o.user_id
             WHERE o.id = :id
             LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(string $orderId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, product_id, variant_id, product_name, product_image_url,
                    variant_size, unit_price, quantity, line_total
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY created_at ASC, id ASC'
        );
        $statement->bindValue(':order_id', $orderId, PDO::PARAM_STR);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * Statuses an order may move to from its current status. The current
     * status is included so it can be re-submitted without changing anything.
     *
     * @return list<string>
     */
    public static function allowedTransitions(string $current): array
    {
        $next = self::TRANSITIONS[$current] ?? [];

        return array_values(array_filter(
            self::STATUSES,
            static fn (string $status): bool => $status === $current || in_array($status, $next, true)
        ));
    }

    public function updateStatus(string $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new ApiException(422, 'invalid_status', 'The order status is not supported.');
        }

        $order = $this->find($id);
        if ($order === null) {
            throw new ApiException(404, 'order_not_found', 'Order not found.');
        }

        $current = (string) $order['status'];
        if ($status === $current) {
            return;
        }
        if (!in_array($status, self::allowedTransitions($current), true)) {
            throw new ApiException(
                422,
                'invalid_transition',
                sprintf('An order cannot move from %s to %s.', $current, $status)
            );
        }

        $statement = $this->db->prepare('UPDATE orders SET status = :status WHERE id = :id');
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 6): array
    {
        $statement = $this->db->prepare(
            'SELECT o.id, o.order_number, o.status, o.total_amount, o.currency,
                    o.shipping_full_name, o.created_at,
                    u.email AS customer_email,
                    COUNT(oi.id) AS item_count
             FROM orders o
             JOIN users u ON u.id = o.user_id
             LEFT JOIN order_items oi ON oi.order_id = o.id
             GROUP BY o.id, u.email
             ORDER BY o.created_at DESC
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
            $conditions[] = '(o.order_number ILIKE :search OR o.shipping_email ILIKE :search
                OR o.shipping_full_name ILIKE :search OR u.email ILIKE :search)';
            $params[':search'] = $term;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw new ApiException(422, 'invalid_status', 'The order status filter is not supported.');
            }
            $conditions[] = 'o.status = :status';
            $params[':status'] = $status;
        }

        $dateFrom = $this->validDate($filters['date_from'] ?? '', 'The start date is not valid.');
        $dateTo = $this->validDate($filters['date_to'] ?? '', 'The end date is not valid.');
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new ApiException(422, 'invalid_date_range', 'The start date must be on or before the end date.');
        }
        if ($dateFrom !== null) {
            $conditions[] = 'o.created_at >= CAST(:date_from AS date)';
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $conditions[] = "o.created_at < (CAST(:date_to AS date) + INTERVAL '1 day')";
            $params[':date_to'] = $dateTo;
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function validDate(mixed $value, string $message): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ApiException(422, 'invalid_date', $message);
        }

        return $value;
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
}

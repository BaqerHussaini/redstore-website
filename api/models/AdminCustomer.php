<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ApiException.php';

final class AdminCustomer
{
    /** @var array<string, string> */
    private const SORTS = [
        'newest' => 'u.created_at DESC, u.id ASC',
        'oldest' => 'u.created_at ASC, u.id ASC',
        'name_asc' => "COALESCE(NULLIF(u.full_name, ''), u.email::text) ASC",
        'name_desc' => "COALESCE(NULLIF(u.full_name, ''), u.email::text) DESC",
        'orders_desc' => 'order_count DESC, u.created_at DESC',
        'spent_desc' => 'total_spent DESC, u.created_at DESC',
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
        $search = trim((string) ($filters['search'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $sort = self::SORTS[(string) ($filters['sort'] ?? 'newest')] ?? self::SORTS['newest'];

        $where = "u.role = 'user'";
        $params = [];
        if ($search !== '') {
            $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $where .= ' AND (u.email ILIKE :search OR u.full_name ILIKE :search)';
            $params[':search'] = $term;
        }
        if ($status === 'active') {
            $where .= ' AND u.is_active = true';
        } elseif ($status === 'inactive') {
            $where .= ' AND u.is_active = false';
        }

        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $count = $this->db->prepare('SELECT COUNT(*) FROM users u WHERE ' . $where);
        foreach ($params as $key => $value) {
            $count->bindValue($key, $value, PDO::PARAM_STR);
        }
        $count->execute();
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT
                    u.id, u.email, u.full_name, u.role, u.is_active, u.created_at,
                    COUNT(o.id) AS order_count,
                    COUNT(o.id) FILTER (
                        WHERE o.status NOT IN (\'cancelled\', \'refunded\')
                    ) AS valid_order_count,
                    COALESCE(SUM(o.total_amount) FILTER (
                        WHERE o.status NOT IN (\'cancelled\', \'refunded\')
                    ), 0) AS total_spent,
                    MAX(o.created_at) AS last_order_at
                FROM users u
                LEFT JOIN orders o ON o.user_id = u.id
                WHERE ' . $where . '
                GROUP BY u.id, u.email, u.full_name, u.role, u.is_active, u.created_at
                ORDER BY ' . $sort . '
                LIMIT :limit OFFSET :offset';

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
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
            'SELECT
                u.id, u.email, u.full_name, u.role, u.is_active, u.created_at, u.updated_at,
                COUNT(o.id) AS order_count,
                COUNT(o.id) FILTER (
                    WHERE o.status NOT IN (\'cancelled\', \'refunded\')
                ) AS valid_order_count,
                COALESCE(SUM(o.total_amount) FILTER (
                    WHERE o.status NOT IN (\'cancelled\', \'refunded\')
                ), 0) AS total_spent,
                MAX(o.created_at) AS last_order_at
             FROM users u
             LEFT JOIN orders o ON o.user_id = u.id
             WHERE u.id = :id AND u.role = \'user\'
             GROUP BY u.id, u.email, u.full_name, u.role, u.is_active, u.created_at, u.updated_at
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
    public function orders(string $customerId, int $limit = 100): array
    {
        $statement = $this->db->prepare(
            'SELECT
                o.id, o.order_number, o.status, o.subtotal, o.shipping_fee,
                o.total_amount, o.currency, o.shipping_full_name, o.shipping_email,
                o.shipping_phone, o.shipping_address_line1, o.shipping_address_line2,
                o.shipping_city, o.shipping_state, o.shipping_postal_code,
                o.shipping_country, o.created_at,
                COUNT(oi.id) AS item_count
             FROM orders o
             LEFT JOIN order_items oi ON oi.order_id = o.id
             WHERE o.user_id = :user_id
             GROUP BY o.id
             ORDER BY o.created_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':user_id', $customerId, PDO::PARAM_STR);
        $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
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
                u.id, u.email, u.full_name, u.is_active, u.created_at,
                COUNT(o.id) AS order_count
             FROM users u
             LEFT JOIN orders o ON o.user_id = u.id
             WHERE u.role = \'user\'
             GROUP BY u.id, u.email, u.full_name, u.is_active, u.created_at
             ORDER BY u.created_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, min(20, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    public function updateStatus(string $id, bool $isActive): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new ApiException(404, 'customer_not_found', 'Customer not found.');
        }

        $statement = $this->db->prepare(
            'UPDATE users SET is_active = :is_active WHERE id = :id AND role = \'user\''
        );
        $statement->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();
    }

    public function total(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
    }
}

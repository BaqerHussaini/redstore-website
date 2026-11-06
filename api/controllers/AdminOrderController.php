<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminOrder.php';
require_once __DIR__ . '/../lib/ApiException.php';

final class AdminOrderController
{
    private AdminOrder $orders;

    public function __construct(?PDO $db = null)
    {
        $this->orders = new AdminOrder($db ?? redstore_db());
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
        $status = trim((string) ($query['status'] ?? ''));
        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        $dateTo = trim((string) ($query['date_to'] ?? ''));
        $sort = trim((string) ($query['sort'] ?? 'newest'));

        if (mb_strlen($search) > 120) {
            throw new ApiException(422, 'invalid_search', 'The search term must be 120 characters or fewer.');
        }
        if ($status !== '' && !in_array($status, AdminOrder::STATUSES, true)) {
            throw new ApiException(422, 'invalid_status', 'The order status filter is not supported.');
        }
        if (!in_array($sort, ['newest', 'oldest', 'total_desc', 'total_asc', 'updated'], true)) {
            throw new ApiException(422, 'invalid_sort', 'The order sort value is not supported.');
        }

        [$rows, $total] = $this->orders->paginate([
            'search' => $search,
            'status' => $status,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
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
                    'status' => $status,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'sort' => $sort,
                ],
                'statuses' => AdminOrder::STATUSES,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $order = $this->orders->find($this->uuidOrFail($id));
        if ($order === null) {
            throw new ApiException(404, 'order_not_found', 'Order not found.');
        }

        $order['items'] = $this->orders->items($id);
        $order['statuses'] = AdminOrder::STATUSES;
        $order['allowed_statuses'] = AdminOrder::allowedTransitions((string) $order['status']);

        return $order;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateStatus(string $id, array $input): array
    {
        $id = $this->uuidOrFail($id);
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        $this->orders->updateStatus($id, $status);

        return $this->show($id);
    }

    private function uuidOrFail(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) !== 1) {
            throw new ApiException(404, 'order_not_found', 'Order not found.');
        }

        return strtolower($id);
    }
}

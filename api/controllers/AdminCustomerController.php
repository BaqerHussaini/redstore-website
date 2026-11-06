<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminCustomer.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';

final class AdminCustomerController
{
    private AdminCustomer $customers;

    public function __construct(?PDO $db = null)
    {
        $this->customers = new AdminCustomer($db ?? redstore_db());
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
        $sort = trim((string) ($query['sort'] ?? 'newest'));

        if (mb_strlen($search) > 120) {
            throw new ApiException(422, 'invalid_search', 'The search term must be 120 characters or fewer.');
        }
        if (!in_array($status, ['', 'active', 'inactive'], true)) {
            throw new ApiException(422, 'invalid_status', 'The customer status filter is not valid.');
        }
        if (!in_array($sort, ['newest', 'oldest', 'name_asc', 'name_desc', 'orders_desc', 'spent_desc'], true)) {
            throw new ApiException(422, 'invalid_sort', 'The customer sort value is not supported.');
        }

        [$rows, $total] = $this->customers->paginate([
            'search' => $search,
            'status' => $status,
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
                'filters' => ['search' => $search, 'status' => $status, 'sort' => $sort],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $id = $this->uuidOrFail($id);
        $customer = $this->customers->find($id);
        if ($customer === null) {
            throw new ApiException(404, 'customer_not_found', 'Customer not found.');
        }

        $customer['orders'] = $this->customers->orders($id);

        return $customer;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateStatus(string $id, array $input): array
    {
        $id = $this->uuidOrFail($id);

        if (array_key_exists('is_active', $input)) {
            $isActive = Input::boolean($input, 'is_active');
        } else {
            $status = strtolower(trim((string) ($input['status'] ?? '')));
            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new ApiException(422, 'invalid_status', 'Choose whether the customer account is active or inactive.');
            }
            $isActive = $status === 'active';
        }

        $this->customers->updateStatus($id, $isActive);

        return $this->show($id);
    }

    private function uuidOrFail(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) !== 1) {
            throw new ApiException(404, 'customer_not_found', 'Customer not found.');
        }

        return strtolower($id);
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminInventory.php';
require_once __DIR__ . '/../models/AdminProduct.php';
require_once __DIR__ . '/../controllers/AdminProductController.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';

final class AdminInventoryController
{
    private AdminInventory $inventory;
    private AdminProduct $products;
    private AdminProductController $productController;

    public function __construct(?PDO $db = null)
    {
        $db ??= redstore_db();
        $this->inventory = new AdminInventory($db);
        $this->products = new AdminProduct($db);
        $this->productController = new AdminProductController($db);
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
        $lowStock = Input::boolean($query, 'low_stock');
        $stockStatus = trim((string) ($query['stock_status'] ?? ''));

        if (mb_strlen($search) > 120) {
            throw new ApiException(422, 'invalid_search', 'The search term must be 120 characters or fewer.');
        }
        if (!in_array($stockStatus, ['', 'in', 'low', 'out'], true)) {
            throw new ApiException(422, 'invalid_stock_status', 'The stock status filter is not valid.');
        }
        if ($stockStatus === '') {
            $stockStatus = $lowStock ? 'low' : '';
        }

        [$rows, $total] = $this->inventory->paginate([
            'search' => $search,
            'low_stock' => $lowStock,
            'stock_status' => $stockStatus,
            'threshold' => AdminProduct::LOW_STOCK_THRESHOLD,
            'page' => $page,
            'limit' => $limit,
        ]);

        foreach ($rows as &$row) {
            $row['variants'] = $this->products->variants((string) $row['id']);
        }
        unset($row);

        return [
            $rows,
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
                'filters' => [
                    'search' => $search,
                    'low_stock' => $lowStock,
                    'stock_status' => $stockStatus,
                ],
                'threshold' => AdminProduct::LOW_STOCK_THRESHOLD,
            ],
        ];
    }

    public function updateProductStock(string $productId, mixed $stock): int
    {
        $filtered = filter_var($stock, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 1000000],
        ]);
        if ($filtered === false) {
            throw new ApiException(422, 'invalid_stock', 'Stock must be a whole number between 0 and 1,000,000.');
        }

        return $this->productController->setStock($productId, (int) $filtered);
    }

    public function updateVariantStock(string $variantId, mixed $stock): int
    {
        $filtered = filter_var($stock, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 1000000],
        ]);
        if ($filtered === false) {
            throw new ApiException(422, 'invalid_stock', 'Stock must be a whole number between 0 and 1,000,000.');
        }

        return $this->productController->setVariantStock($variantId, (int) $filtered);
    }
}

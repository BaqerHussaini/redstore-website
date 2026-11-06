<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminDashboard.php';
require_once __DIR__ . '/../models/AdminProduct.php';
require_once __DIR__ . '/../models/AdminOrder.php';
require_once __DIR__ . '/../models/AdminCustomer.php';

final class AdminDashboardController
{
    private AdminDashboard $dashboard;
    private AdminProduct $products;
    private AdminOrder $orders;
    private AdminCustomer $customers;

    public function __construct(?PDO $db = null)
    {
        $db ??= redstore_db();
        $this->dashboard = new AdminDashboard($db);
        $this->products = new AdminProduct($db);
        $this->orders = new AdminOrder($db);
        $this->customers = new AdminCustomer($db);
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'stats' => $this->dashboard->stats(AdminProduct::LOW_STOCK_THRESHOLD),
            'recent_orders' => $this->orders->recent(6),
            'recent_customers' => $this->customers->recent(6),
            'recent_products' => $this->products->recent(6),
            'best_sellers' => $this->dashboard->bestSellers(6),
            'low_stock' => $this->products->lowStock(6),
            'order_statuses' => $this->dashboard->orderStatusCounts(),
            'low_stock_threshold' => AdminProduct::LOW_STOCK_THRESHOLD,
        ];
    }
}

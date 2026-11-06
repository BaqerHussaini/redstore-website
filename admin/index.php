<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$currentAdmin = Auth::user();
$GLOBALS['currentAdmin'] = $currentAdmin;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($adminPath === '/login') {
    if ($currentAdmin !== null) {
        admin_redirect('');
    }

    $error = null;
    $email = '';
    $retryAfter = null;

    if ($method === 'POST') {
        $email = is_array($_POST['email'] ?? null) ? '' : trim((string) ($_POST['email'] ?? ''));
        $token = $_POST['_csrf'] ?? null;

        if (!Auth::validateCsrf(is_string($token) ? $token : null)) {
            http_response_code(403);
            $error = 'Your sign-in session expired. Refresh the page and try again.';
        } else {
            $result = Auth::login($email, (string) ($_POST['password'] ?? ''));
            if ($result['ok']) {
                admin_redirect('');
            }
            $error = $result['message'];
            $retryAfter = $result['retry_after'] ?? null;
        }
    }

    if ($retryAfter !== null) {
        http_response_code(429);
        header('Retry-After: ' . (int) $retryAfter);
    }

    require __DIR__ . '/views/login.php';
    exit;
}

if ($adminPath === '/logout') {
    if ($method !== 'POST') {
        admin_redirect($currentAdmin === null ? 'login' : '');
    }

    $token = $_POST['_csrf'] ?? null;
    if (!Auth::validateCsrf(is_string($token) ? $token : null)) {
        admin_error_page(403, 'The security token was invalid. Please try signing out again.');
    }

    Auth::logout();
    admin_redirect('login');
}

if ($currentAdmin === null) {
    admin_redirect('login');
}

if ($method === 'POST') {
    $token = $_POST['_csrf'] ?? null;
    if (!Auth::validateCsrf(is_string($token) ? $token : null)) {
        admin_error_page(403, 'The security token is missing or invalid. Refresh the page and try again.');
    }
}

$fallbackPath = static function (string $path): string {
    if (preg_match('#^/products/edit/([0-9a-f-]{36})$#i', $path, $matches) === 1) {
        return 'products/edit/' . $matches[1];
    }
    if (preg_match('#^/orders/view/([0-9a-f-]{36})$#i', $path, $matches) === 1) {
        return 'orders/view/' . $matches[1];
    }
    foreach (['products', 'categories', 'inventory', 'orders', 'customers', 'settings'] as $section) {
        if ($path === '/' . $section || str_starts_with($path, '/' . $section . '/')) {
            return $section;
        }
    }

    return '';
};

$defaultProduct = static function (): array {
    return [
        'id' => '',
        'name' => '',
        'slug' => '',
        'description' => '',
        'category_id' => '',
        'price' => '',
        'sale_price' => '',
        'stock_quantity' => 0,
        'is_featured' => false,
        'is_active' => true,
    ];
};

try {
    if ($adminPath === '/' && $method === 'GET') {
        $overview = (new AdminDashboardController())->overview();
        admin_view('dashboard', array_merge($overview, [
            'title' => 'Dashboard',
            'active' => 'dashboard',
        ]));
        return;
    }

    if ($adminPath === '/products' && $method === 'GET') {
        $controller = new AdminProductController();
        [$products, $meta] = $controller->index($_GET);
        $categories = (new AdminCategoryController())->all();
        admin_view('products', [
            'title' => 'Products',
            'active' => 'products',
            'products' => $products,
            'meta' => $meta,
            'categories' => $categories,
        ]);
        return;
    }

    if ($adminPath === '/products/create' && $method === 'GET') {
        admin_view('product-form', [
            'title' => 'Add product',
            'active' => 'products',
            'mode' => 'create',
            'product' => $defaultProduct(),
            'categories' => (new AdminCategoryController())->all(),
            'images' => [],
            'variants' => [],
            'formError' => null,
        ]);
        return;
    }

    if ($adminPath === '/products/create' && $method === 'POST') {
        $controller = new AdminProductController();
        try {
            $product = $controller->create($_POST, $_FILES);
            admin_flash('success', 'Product created.');
            admin_redirect('products/edit/' . $product['id']);
        } catch (ApiException $exception) {
            http_response_code($exception->status);
            $product = array_merge($defaultProduct(), is_array($_POST) ? $_POST : []);
            admin_view('product-form', [
                'title' => 'Add product',
                'active' => 'products',
                'mode' => 'create',
                'product' => $product,
                'categories' => (new AdminCategoryController())->all(),
                'images' => [],
                'variants' => is_array($_POST['variants'] ?? null) ? $_POST['variants'] : [],
                'formError' => $exception->getMessage(),
            ]);
            return;
        }
    }

    if (preg_match('#^/products/edit/([0-9a-f-]{36})$#i', $adminPath, $matches) === 1) {
        $controller = new AdminProductController();
        $id = $matches[1];

        if ($method === 'GET') {
            $product = $controller->show($id);
            admin_view('product-form', [
                'title' => 'Edit product',
                'active' => 'products',
                'mode' => 'edit',
                'product' => $product,
                'categories' => (new AdminCategoryController())->all(),
                'images' => $product['images'] ?? [],
                'variants' => $product['variants'] ?? [],
                'formError' => null,
            ]);
            return;
        }

        if ($method === 'POST') {
            try {
                $controller->update($id, $_POST, $_FILES);
                admin_flash('success', 'Product updated.');
                admin_redirect('products/edit/' . $id);
            } catch (ApiException $exception) {
                http_response_code($exception->status);
                $product = $controller->show($id);
                foreach (['name', 'slug', 'description', 'category_id', 'price', 'sale_price', 'stock_quantity', 'is_featured', 'is_active'] as $field) {
                    if (array_key_exists($field, $_POST)) {
                        $product[$field] = $_POST[$field];
                    }
                }
                admin_view('product-form', [
                    'title' => 'Edit product',
                    'active' => 'products',
                    'mode' => 'edit',
                    'product' => $product,
                    'categories' => (new AdminCategoryController())->all(),
                    'images' => $product['images'] ?? [],
                    'variants' => is_array($_POST['variants'] ?? null) ? $_POST['variants'] : ($product['variants'] ?? []),
                    'formError' => $exception->getMessage(),
                ]);
                return;
            }
        }
    }

    if (preg_match('#^/products/delete/([0-9a-f-]{36})$#i', $adminPath, $matches) === 1 && $method === 'POST') {
        $mode = trim((string) ($_POST['mode'] ?? 'deactivate'));
        $hard = $mode === 'delete';
        try {
            $result = (new AdminProductController())->delete($matches[1], $hard);
            admin_flash(
                'success',
                !empty($result['deleted']) ? 'Product permanently deleted.' : 'Product deactivated.'
            );
        } catch (ApiException $exception) {
            admin_flash('error', $exception->getMessage());
        }
        admin_redirect('products');
    }

    if ($adminPath === '/categories' && $method === 'GET') {
        $controller = new AdminCategoryController();
        [$categories, $meta] = $controller->index($_GET);
        admin_view('categories', [
            'title' => 'Categories',
            'active' => 'categories',
            'categories' => $categories,
            'all_categories' => $controller->all(),
            'meta' => $meta,
        ]);
        return;
    }

    if ($adminPath === '/categories/create' && $method === 'POST') {
        try {
            (new AdminCategoryController())->create($_POST);
            admin_flash('success', 'Category created.');
        } catch (ApiException $exception) {
            admin_flash('error', $exception->getMessage());
        }
        admin_redirect('categories');
    }

    if (preg_match('#^/categories/([0-9a-f-]{36})/update$#i', $adminPath, $matches) === 1 && $method === 'POST') {
        try {
            (new AdminCategoryController())->update($matches[1], $_POST);
            admin_flash('success', 'Category updated.');
        } catch (ApiException $exception) {
            admin_flash('error', $exception->getMessage());
        }
        admin_redirect('categories');
    }

    if (preg_match('#^/categories/([0-9a-f-]{36})/delete$#i', $adminPath, $matches) === 1 && $method === 'POST') {
        try {
            $result = (new AdminCategoryController())->delete($matches[1], $_POST);
            $message = 'Category deleted.';
            if (($result['reassigned'] ?? 0) > 0) {
                $message .= ' ' . (int) $result['reassigned'] . ' product(s) were reassigned.';
            } elseif (($result['unassigned'] ?? 0) > 0) {
                $message .= ' ' . (int) $result['unassigned'] . ' product(s) were left uncategorised.';
            }
            admin_flash('success', $message);
        } catch (ApiException $exception) {
            admin_flash('error', $exception->getMessage());
        }
        admin_redirect('categories');
    }

    if ($adminPath === '/inventory' && $method === 'GET') {
        [$items, $meta] = (new AdminInventoryController())->index($_GET);
        admin_view('inventory', [
            'title' => 'Inventory',
            'active' => 'inventory',
            'items' => $items,
            'meta' => $meta,
        ]);
        return;
    }

    if (preg_match('#^/inventory/product/([0-9a-f-]{36})$#i', $adminPath, $matches) === 1 && $method === 'POST') {
        try {
            $stock = (new AdminInventoryController())->updateProductStock($matches[1], $_POST['stock_quantity'] ?? null);
            admin_flash('success', 'Product stock updated to ' . $stock . '.');
        } catch (ApiException $exception) {
            admin_flash('error', $exception->getMessage());
        }
        admin_redirect('inventory');
    }

    if (preg_match('#^/inventory/variant/([0-9a-f-]{36})$#i', $adminPath, $matches) === 1 && $method === 'POST') {
        try {
            $stock = (new AdminInventoryController())->updateVariantStock($matches[1], $_POST['stock_quantity'] ?? null);
            admin_flash('success', 'Variant stock updated. Product total is now ' . $stock . '.');
        } catch (ApiException $exception) {
            admin_flash('error', $exception->getMessage());
        }
        admin_redirect('inventory');
    }

    if ($adminPath === '/orders' && $method === 'GET') {
        [$orders, $meta] = (new AdminOrderController())->index($_GET);
        admin_view('orders', [
            'title' => 'Orders',
            'active' => 'orders',
            'orders' => $orders,
            'meta' => $meta,
        ]);
        return;
    }

    if (preg_match('#^/orders/view/([0-9a-f-]{36})$#i', $adminPath, $matches) === 1 && $method === 'GET') {
        $order = (new AdminOrderController())->show($matches[1]);
        admin_view('order-view', [
            'title' => 'Order ' . $order['order_number'],
            'active' => 'orders',
            'order' => $order,
            'items' => $order['items'] ?? [],
            'statuses' => $order['allowed_statuses'] ?? AdminOrder::STATUSES,
        ]);
        return;
    }

    if (preg_match('#^/orders/([0-9a-f-]{36})/status$#i', $adminPath, $matches) === 1 && $method === 'POST') {
        try {
            (new AdminOrderController())->updateStatus($matches[1], $_POST);
            admin_flash('success', 'Order status updated.');
        } catch (ApiException $exception) {
            admin_flash('error', $exception->getMessage());
        }
        admin_redirect('orders/view/' . $matches[1]);
    }

    if ($adminPath === '/customers' && $method === 'GET') {
        [$customers, $meta] = (new AdminCustomerController())->index($_GET);
        admin_view('customers', [
            'title' => 'Customers',
            'active' => 'customers',
            'customers' => $customers,
            'meta' => $meta,
        ]);
        return;
    }

    if (preg_match('#^/customers/view/([0-9a-f-]{36})$#i', $adminPath, $matches) === 1 && $method === 'GET') {
        $customer = (new AdminCustomerController())->show($matches[1]);
        admin_view('customer-view', [
            'title' => 'Customer ' . ($customer['full_name'] ?: $customer['email']),
            'active' => 'customers',
            'customer' => $customer,
        ]);
        return;
    }

    if (preg_match('#^/customers/([0-9a-f-]{36})/status$#i', $adminPath, $matches) === 1 && $method === 'POST') {
        try {
            $customer = (new AdminCustomerController())->updateStatus($matches[1], $_POST);
            admin_flash('success', 'Customer account is now ' . (!empty($customer['is_active']) ? 'active.' : 'inactive.'));
        } catch (ApiException $exception) {
            admin_flash('error', $exception->getMessage());
        }
        admin_redirect('customers/view/' . $matches[1]);
    }

    if ($adminPath === '/settings' && $method === 'GET') {
        $config = redstore_config();
        $cookieParams = session_get_cookie_params();
        admin_view('settings', [
            'title' => 'Settings',
            'active' => 'settings',
            'account' => $currentAdmin,
            'integration' => [
                'app_env' => (string) ($config['app_env'] ?? ''),
                'storage_configured' => (new SupabaseStorage())->isConfigured(),
                'storage_bucket' => (string) ($config['storage_bucket'] ?? 'product-images'),
                'secure_cookie' => (bool) ($cookieParams['secure'] ?? false),
                'low_stock_threshold' => AdminProduct::LOW_STOCK_THRESHOLD,
            ],
        ]);
        return;
    }

    if ($adminPath === '/settings/profile' && $method === 'POST') {
        $result = Auth::updateOwnProfile(
            (string) $currentAdmin['id'],
            (string) ($_POST['full_name'] ?? ''),
            (string) ($_POST['email'] ?? '')
        );
        if ($result['ok']) {
            admin_flash('success', $result['message']);
        } else {
            admin_flash('error', $result['message']);
        }
        admin_redirect('settings');
    }

    if ($adminPath === '/settings/password' && $method === 'POST') {
        $result = Auth::changeOwnPassword(
            (string) $currentAdmin['id'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        if ($result['ok']) {
            admin_flash('success', $result['message']);
        } else {
            admin_flash('error', $result['message']);
        }
        admin_redirect('settings');
    }

    admin_error_page(404, 'The admin page you requested does not exist.');
} catch (ApiException $exception) {
    if ($method === 'POST') {
        admin_flash('error', $exception->getMessage());
        admin_redirect($fallbackPath($adminPath));
    }

    admin_error_page($exception->status, $exception->getMessage());
} catch (Throwable $exception) {
    error_log(
        '[RedStore Admin] Unhandled ' . $exception::class . ': '
        . $exception->getMessage()
        . ' in ' . $exception->getFile() . ':' . $exception->getLine()
    );

    if ($method === 'POST') {
        admin_flash('error', 'An unexpected server error occurred. Please try again.');
        admin_redirect($fallbackPath($adminPath));
    }

    admin_error_page(500, 'An unexpected server error occurred.');
}

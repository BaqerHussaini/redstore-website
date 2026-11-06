<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Response.php';
require_once __DIR__ . '/../lib/Input.php';
require_once __DIR__ . '/../controllers/AdminProductController.php';
require_once __DIR__ . '/../controllers/AdminCategoryController.php';
require_once __DIR__ . '/../controllers/AdminInventoryController.php';
require_once __DIR__ . '/../controllers/AdminOrderController.php';
require_once __DIR__ . '/../controllers/AdminCustomerController.php';
require_once __DIR__ . '/../controllers/AdminDashboardController.php';

if (!function_exists('admin_api_input')) {
    /**
     * @return array<string, mixed>
     */
    function admin_api_input(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === false || trim($raw) === '') {
                return [];
            }
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ApiException(400, 'invalid_json', 'The request body is not valid JSON.');
            }
            if (!is_array($decoded)) {
                throw new ApiException(400, 'invalid_json', 'The JSON request body must be an object.');
            }
            return $decoded;
        }

        return is_array($_POST) ? $_POST : [];
    }
}

/**
 * @return callable(string, string): void
 */
return static function (string $method, string $path): void {
    if (!str_starts_with($path, '/admin')) {
        throw new ApiException(404, 'not_found', 'API endpoint not found.');
    }

    $route = substr($path, strlen('/admin'));
    $route = '/' . trim((string) $route, '/');
    $route = urldecode($route);
    $method = strtoupper($method);

    Auth::startSession();
    $user = Auth::requireAdmin();

    $input = [];
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $input = admin_api_input();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
        Auth::requireCsrf(is_string($token) ? $token : null);
    }

    $methodNotAllowed = static function (string $allow): never {
        header('Allow: ' . $allow);
        throw new ApiException(405, 'method_not_allowed', 'That request method is not supported for this endpoint.');
    };

    if ($route === '/me') {
        if ($method !== 'GET') {
            $methodNotAllowed('GET');
        }
        Response::success($user);
        return;
    }

    if ($route === '/dashboard') {
        if ($method !== 'GET') {
            $methodNotAllowed('GET');
        }
        Response::success((new AdminDashboardController())->overview());
        return;
    }

    if ($route === '/products') {
        $controller = new AdminProductController();
        if ($method === 'GET') {
            [$products, $meta] = $controller->index($_GET);
            Response::success($products, $meta);
            return;
        }
        if ($method === 'POST') {
            Response::success($controller->create($input, $_FILES), null, 201);
            return;
        }
        $methodNotAllowed('GET, POST');
    }

    if ($route === '/inventory') {
        if ($method !== 'GET') {
            $methodNotAllowed('GET');
        }
        [$items, $meta] = (new AdminInventoryController())->index($_GET);
        Response::success($items, $meta);
        return;
    }

    if (preg_match('#^/inventory/products/([0-9a-f-]{36})$#i', $route, $matches) === 1) {
        if (!in_array($method, ['PATCH', 'PUT'], true)) {
            $methodNotAllowed('PATCH, PUT');
        }
        $stock = (new AdminInventoryController())->updateProductStock($matches[1], $input['stock_quantity'] ?? null);
        Response::success(['product_id' => $matches[1], 'stock_quantity' => $stock]);
        return;
    }

    if (preg_match('#^/inventory/variants/([0-9a-f-]{36})$#i', $route, $matches) === 1) {
        if (!in_array($method, ['PATCH', 'PUT'], true)) {
            $methodNotAllowed('PATCH, PUT');
        }
        $stock = (new AdminInventoryController())->updateVariantStock($matches[1], $input['stock_quantity'] ?? null);
        Response::success(['variant_id' => $matches[1], 'aggregate_stock' => $stock]);
        return;
    }

    if ($route === '/categories') {
        $controller = new AdminCategoryController();
        if ($method === 'GET') {
            [$categories, $meta] = $controller->index($_GET);
            Response::success($categories, $meta);
            return;
        }
        if ($method === 'POST') {
            Response::success($controller->create($input), null, 201);
            return;
        }
        $methodNotAllowed('GET, POST');
    }

    if (preg_match('#^/categories/([0-9a-f-]{36})$#i', $route, $matches) === 1) {
        $controller = new AdminCategoryController();
        if (in_array($method, ['PUT', 'PATCH'], true)) {
            Response::success($controller->update($matches[1], $input));
            return;
        }
        if ($method === 'DELETE') {
            Response::success($controller->delete($matches[1], $input));
            return;
        }
        if ($method === 'GET') {
            Response::success($controller->show($matches[1]));
            return;
        }
        $methodNotAllowed('GET, PUT, PATCH, DELETE');
    }

    if (preg_match('#^/products/([0-9a-f-]{36})/images/reorder$#i', $route, $matches) === 1) {
        if ($method !== 'POST') {
            $methodNotAllowed('POST');
        }
        Response::success((new AdminProductController())->reorderImages($matches[1], $input));
        return;
    }

    if (preg_match('#^/products/([0-9a-f-]{36})/images$#i', $route, $matches) === 1) {
        if ($method !== 'POST') {
            $methodNotAllowed('POST');
        }
        Response::success((new AdminProductController())->addImages($matches[1], $input, $_FILES), null, 201);
        return;
    }

    if (preg_match('#^/products/([0-9a-f-]{36})/stock$#i', $route, $matches) === 1) {
        if (!in_array($method, ['PATCH', 'PUT'], true)) {
            $methodNotAllowed('PATCH, PUT');
        }
        $stock = (new AdminProductController())->setStock($matches[1], (int) ($input['stock_quantity'] ?? -1));
        Response::success(['product_id' => $matches[1], 'stock_quantity' => $stock]);
        return;
    }

    if (preg_match('#^/products/([0-9a-f-]{36})$#i', $route, $matches) === 1) {
        $controller = new AdminProductController();
        if (in_array($method, ['PUT', 'PATCH'], true)) {
            Response::success($controller->update($matches[1], $input, $_FILES));
            return;
        }
        if ($method === 'DELETE') {
            $hard = in_array(strtolower((string) ($_GET['hard'] ?? '0')), ['1', 'true', 'yes'], true);
            Response::success($controller->delete($matches[1], $hard));
            return;
        }
        if ($method === 'GET') {
            Response::success($controller->show($matches[1]));
            return;
        }
        $methodNotAllowed('GET, PUT, PATCH, DELETE');
    }

    if (preg_match('#^/images/([0-9a-f-]{36})$#i', $route, $matches) === 1) {
        $controller = new AdminProductController();
        if (in_array($method, ['PATCH', 'PUT'], true)) {
            Response::success($controller->updateImageMeta($matches[1], $input));
            return;
        }
        if ($method === 'DELETE') {
            Response::success($controller->deleteImage($matches[1]));
            return;
        }
        if ($method === 'GET') {
            Response::success($controller->image($matches[1]));
            return;
        }
        $methodNotAllowed('GET, PATCH, PUT, DELETE');
    }

    if ($route === '/orders') {
        if ($method !== 'GET') {
            $methodNotAllowed('GET');
        }
        [$orders, $meta] = (new AdminOrderController())->index($_GET);
        Response::success($orders, $meta);
        return;
    }

    if (preg_match('#^/orders/([0-9a-f-]{36})/status$#i', $route, $matches) === 1) {
        if (!in_array($method, ['PATCH', 'PUT'], true)) {
            $methodNotAllowed('PATCH, PUT');
        }
        Response::success((new AdminOrderController())->updateStatus($matches[1], $input));
        return;
    }

    if (preg_match('#^/orders/([0-9a-f-]{36})$#i', $route, $matches) === 1) {
        if ($method !== 'GET') {
            $methodNotAllowed('GET');
        }
        Response::success((new AdminOrderController())->show($matches[1]));
        return;
    }

    if ($route === '/customers') {
        if ($method !== 'GET') {
            $methodNotAllowed('GET');
        }
        [$customers, $meta] = (new AdminCustomerController())->index($_GET);
        Response::success($customers, $meta);
        return;
    }

    if (preg_match('#^/customers/([0-9a-f-]{36})/status$#i', $route, $matches) === 1) {
        if (!in_array($method, ['PATCH', 'PUT'], true)) {
            $methodNotAllowed('PATCH, PUT');
        }
        Response::success((new AdminCustomerController())->updateStatus($matches[1], $input));
        return;
    }

    if (preg_match('#^/customers/([0-9a-f-]{36})$#i', $route, $matches) === 1) {
        if ($method !== 'GET') {
            $methodNotAllowed('GET');
        }
        Response::success((new AdminCustomerController())->show($matches[1]));
        return;
    }

    if ($route === '/account') {
        if ($method === 'GET') {
            Response::success($user);
            return;
        }
        if (in_array($method, ['PATCH', 'PUT'], true)) {
            $result = Auth::updateOwnProfile(
                (string) $user['id'],
                (string) ($input['full_name'] ?? ''),
                (string) ($input['email'] ?? '')
            );
            if (!$result['ok']) {
                throw new ApiException(422, 'profile_update_failed', $result['message']);
            }
            Response::success(Auth::user());
            return;
        }
        $methodNotAllowed('GET, PATCH, PUT');
    }

    if ($route === '/account/password') {
        if ($method !== 'POST') {
            $methodNotAllowed('POST');
        }
        $result = Auth::changeOwnPassword(
            (string) $user['id'],
            (string) ($input['current_password'] ?? ''),
            (string) ($input['new_password'] ?? ''),
            (string) ($input['confirm_password'] ?? '')
        );
        if (!$result['ok']) {
            throw new ApiException(422, 'password_change_failed', $result['message']);
        }
        Response::success(['message' => $result['message']]);
        return;
    }

    throw new ApiException(404, 'not_found', 'Admin API endpoint not found.');
};

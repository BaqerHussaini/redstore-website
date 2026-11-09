<?php
declare(strict_types=1);

require_once __DIR__ . '/../controllers/OrderController.php';
require_once __DIR__ . '/../lib/CustomerAuth.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';
require_once __DIR__ . '/../lib/Response.php';

/**
 * Customer order routes.
 *
 * @return callable(string, string): void
 */
return static function (string $method, string $path): void {
    if ($path !== '/orders') {
        throw new ApiException(404, 'not_found', 'API endpoint not found.');
    }

    header('Allow: POST, OPTIONS');
    if ($method !== 'POST') {
        throw new ApiException(405, 'method_not_allowed', 'Only POST requests are supported.');
    }

    $input = Input::jsonBody();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
    $user = CustomerAuth::requireUser();
    CustomerAuth::requireCsrf(is_string($token) ? $token : null);

    [$data] = (new OrderController(redstore_db()))->place((string) $user['id'], $input);
    Response::success($data, null, 201);
};

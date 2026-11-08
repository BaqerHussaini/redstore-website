<?php
declare(strict_types=1);

require_once __DIR__ . '/../controllers/CartController.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';
require_once __DIR__ . '/../lib/Response.php';

/**
 * Guest cart routes. Guest carts are stored in the browser; the API only
 * validates them against PostgreSQL so prices, stock and names stay
 * authoritative. No credentials or service keys are exposed here.
 *
 * @return callable(string, string): void
 */
return static function (string $method, string $path): void {
    header('Allow: POST, OPTIONS');

    if ($path !== '/cart/validate') {
        throw new ApiException(404, 'not_found', 'API endpoint not found.');
    }

    if ($method !== 'POST') {
        throw new ApiException(405, 'method_not_allowed', 'Only POST requests are supported.');
    }

    $controller = new CartController();
    [$data, $meta] = $controller->validate(Input::jsonBody());
    Response::success($data, $meta);
};

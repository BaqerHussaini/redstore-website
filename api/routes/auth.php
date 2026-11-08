<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CustomerAuth.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';
require_once __DIR__ . '/../lib/Response.php';

/**
 * Storefront account routes.
 *
 * @return callable(string, string): void
 */
return static function (string $method, string $path): void {
    header('Allow: GET, POST, OPTIONS');

    $controller = new AuthController();

    if ($path === '/auth/register') {
        if ($method !== 'POST') {
            throw new ApiException(405, 'method_not_allowed', 'Only POST requests are supported.');
        }
        [$data] = $controller->register(Input::jsonBody());
        Response::success($data, null, 201);
        return;
    }

    if ($path === '/auth/login') {
        if ($method !== 'POST') {
            throw new ApiException(405, 'method_not_allowed', 'Only POST requests are supported.');
        }
        [$data] = $controller->login(Input::jsonBody());
        Response::success($data);
        return;
    }

    if ($path === '/auth/logout') {
        if ($method !== 'POST') {
            throw new ApiException(405, 'method_not_allowed', 'Only POST requests are supported.');
        }
        CustomerAuth::startSession();
        $input = Input::jsonBody();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
        CustomerAuth::requireCsrf(is_string($token) ? $token : null);
        [$data] = $controller->logout();
        Response::success($data);
        return;
    }

    if ($path === '/auth/me') {
        if ($method !== 'GET') {
            throw new ApiException(405, 'method_not_allowed', 'Only GET requests are supported.');
        }
        [$data] = $controller->me();
        Response::success($data);
        return;
    }

    throw new ApiException(404, 'not_found', 'API endpoint not found.');
};

<?php
declare(strict_types=1);

require_once __DIR__ . '/../controllers/CartController.php';
require_once __DIR__ . '/../lib/CustomerAuth.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';
require_once __DIR__ . '/../lib/Response.php';

if (!function_exists('cart_request_input')) {
    /**
     * @return array<string, mixed>
     */
    function cart_request_input(): array
    {
        return Input::jsonBody();
    }
}

if (!function_exists('cart_csrf_token')) {
    /**
     * @param array<string, mixed> $input
     */
    function cart_csrf_token(array $input): ?string
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
        return is_string($token) ? $token : null;
    }
}

/**
 * Cart routes.
 *
 * `/cart/validate` is public and only validates a guest cart. Every other
 * route requires a signed-in customer and is scoped to that session user.
 *
 * @return callable(string, string): void
 */
return static function (string $method, string $path): void {
    $controller = new CartController();

    if ($path === '/cart/validate') {
        header('Allow: POST, OPTIONS');
        if ($method !== 'POST') {
            throw new ApiException(405, 'method_not_allowed', 'Only POST requests are supported.');
        }
        [$data, $meta] = $controller->validate(Input::jsonBody());
        Response::success($data, $meta);
        return;
    }

    if ($path === '/cart') {
        if ($method === 'GET') {
            $user = CustomerAuth::requireUser();
            [$data, $meta] = $controller->show((string) $user['id']);
            Response::success($data, $meta);
            return;
        }

        if ($method === 'DELETE') {
            $input = cart_request_input();
            $user = CustomerAuth::requireUser();
            CustomerAuth::requireCsrf(cart_csrf_token($input));
            [$data, $meta] = $controller->clear((string) $user['id']);
            Response::success($data, $meta);
            return;
        }

        header('Allow: GET, DELETE, OPTIONS');
        throw new ApiException(405, 'method_not_allowed', 'That request method is not supported for this endpoint.');
    }

    if ($path === '/cart/items' && $method === 'POST') {
        $input = cart_request_input();
        $user = CustomerAuth::requireUser();
        CustomerAuth::requireCsrf(cart_csrf_token($input));
        [$data, $meta] = $controller->add((string) $user['id'], $input);
        Response::success($data, $meta, 201);
        return;
    }

    if ($path === '/cart/merge' && $method === 'POST') {
        $input = cart_request_input();
        $user = CustomerAuth::requireUser();
        CustomerAuth::requireCsrf(cart_csrf_token($input));
        [$data, $meta] = $controller->merge((string) $user['id'], $input);
        Response::success($data, $meta);
        return;
    }

    if ($path === '/cart/items' || $path === '/cart/merge') {
        header('Allow: POST, OPTIONS');
        throw new ApiException(405, 'method_not_allowed', 'That request method is not supported for this endpoint.');
    }

    if (preg_match('#^/cart/items/([0-9a-f-]{36})$#i', $path, $matches) === 1) {
        if (!in_array($method, ['PATCH', 'PUT', 'DELETE'], true)) {
            header('Allow: PATCH, PUT, DELETE, OPTIONS');
            throw new ApiException(405, 'method_not_allowed', 'That request method is not supported for this endpoint.');
        }

        $input = cart_request_input();
        $user = CustomerAuth::requireUser();
        CustomerAuth::requireCsrf(cart_csrf_token($input));

        if ($method === 'DELETE') {
            [$data, $meta] = $controller->remove((string) $user['id'], strtolower($matches[1]));
        } else {
            [$data, $meta] = $controller->update((string) $user['id'], strtolower($matches[1]), $input);
        }

        Response::success($data, $meta);
        return;
    }

    throw new ApiException(404, 'not_found', 'API endpoint not found.');
};

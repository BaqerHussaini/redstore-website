<?php
declare(strict_types=1);

require_once __DIR__ . '/../controllers/ProductController.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Response.php';

/**
 * @return callable(string, string): void
 */
return static function (string $method, string $path): void {
    header('Allow: GET, OPTIONS');

    if ($method !== 'GET') {
        throw new ApiException(405, 'method_not_allowed', 'Only GET requests are supported.');
    }

    $controller = new ProductController();

    if ($path === '/products') {
        [$products, $meta] = $controller->index($_GET);
        Response::success($products, $meta);
        return;
    }

    if ($path === '/products/featured') {
        [$products, $meta] = $controller->featured($_GET);
        Response::success($products, $meta);
        return;
    }

    if ($path === '/products/latest') {
        [$products, $meta] = $controller->latest($_GET);
        Response::success($products, $meta);
        return;
    }

    if ($path === '/categories') {
        [$categories, $meta] = $controller->categories();
        Response::success($categories, $meta);
        return;
    }

    if (preg_match('#^/products/([^/]+)$#', $path, $matches) === 1) {
        Response::success($controller->show(urldecode($matches[1])));
        return;
    }

    throw new ApiException(404, 'not_found', 'API endpoint not found.');
};

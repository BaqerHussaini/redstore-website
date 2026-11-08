<?php
declare(strict_types=1);

/**
 * RedStore Product API front controller.
 *
 * Routes:
 *   GET /api/products
 *   GET /api/products/featured
 *   GET /api/products/latest
 *   GET /api/products/{slug}
 *   GET /api/categories
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/lib/ApiException.php';
require_once __DIR__ . '/lib/Response.php';

$config = redstore_config();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? $uri : '/';

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}

if ($basePath !== '' && str_starts_with($uri, $basePath)) {
    $path = substr($uri, strlen($basePath));
} elseif (str_starts_with($uri, '/api')) {
    $path = substr($uri, 4);
} else {
    $path = $uri;
}

$path = '/' . trim($path, '/');
$isAdminApi = str_starts_with($path, '/admin');

$allowedOrigin = $config['cors_allowed_origin'];
if (!$isAdminApi && $allowedOrigin !== '') {
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($allowedOrigin === '*' || $requestOrigin === $allowedOrigin) {
        header('Access-Control-Allow-Origin: ' . ($allowedOrigin === '*' ? '*' : $requestOrigin));
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
}

set_exception_handler(static function (Throwable $exception): void {
    if ($exception instanceof ApiException) {
        Response::error($exception->status, $exception->errorCode, $exception->getMessage());
        return;
    }

    error_log(
        '[RedStore API] Unhandled ' . $exception::class . ': '
        . $exception->getMessage()
        . ' in ' . $exception->getFile() . ':' . $exception->getLine()
    );

    Response::error(500, 'server_error', 'An unexpected server error occurred.');
});

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Allow: ' . ($isAdminApi ? 'GET, POST, PUT, PATCH, DELETE, OPTIONS' : 'GET, POST, OPTIONS'));
    exit;
}

if ($isAdminApi) {
    require_once __DIR__ . '/lib/Auth.php';
    require_once __DIR__ . '/lib/ApiException.php';
    require_once __DIR__ . '/lib/Response.php';
}

try {
    if ($isAdminApi) {
        $router = require __DIR__ . '/routes/admin.php';
    } elseif (str_starts_with($path, '/cart')) {
        $router = require __DIR__ . '/routes/cart.php';
    } else {
        $router = require __DIR__ . '/routes/products.php';
    }
    $router($method, $path);
} catch (ApiException $exception) {
    Response::error($exception->status, $exception->errorCode, $exception->getMessage());
} catch (Throwable $exception) {
    error_log(
        '[RedStore API] Unhandled ' . $exception::class . ': '
        . $exception->getMessage()
        . ' in ' . $exception->getFile() . ':' . $exception->getLine()
    );
    Response::error(500, 'server_error', 'An unexpected server error occurred.');
}

// ----------------------------------------------------------------------------
// Example:
//   curl http://localhost:8080/api/products?category=shoes&sort=price_asc
// ----------------------------------------------------------------------------

<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/api/config/database.php';
require_once dirname(__DIR__) . '/api/lib/ApiException.php';
require_once dirname(__DIR__) . '/api/lib/Auth.php';
require_once dirname(__DIR__) . '/api/lib/Input.php';
require_once dirname(__DIR__) . '/api/lib/ImageUrl.php';
require_once dirname(__DIR__) . '/api/lib/SupabaseStorage.php';
require_once dirname(__DIR__) . '/api/models/AdminProduct.php';
require_once dirname(__DIR__) . '/api/models/AdminCategory.php';
require_once dirname(__DIR__) . '/api/models/AdminOrder.php';
require_once dirname(__DIR__) . '/api/models/AdminCustomer.php';
require_once dirname(__DIR__) . '/api/models/AdminDashboard.php';
require_once dirname(__DIR__) . '/api/models/AdminInventory.php';
require_once dirname(__DIR__) . '/api/controllers/AdminProductController.php';
require_once dirname(__DIR__) . '/api/controllers/AdminCategoryController.php';
require_once dirname(__DIR__) . '/api/controllers/AdminInventoryController.php';
require_once dirname(__DIR__) . '/api/controllers/AdminOrderController.php';
require_once dirname(__DIR__) . '/api/controllers/AdminCustomerController.php';
require_once dirname(__DIR__) . '/api/controllers/AdminDashboardController.php';

Auth::startSession();

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'));
$adminBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($adminBase === '.' || $adminBase === '/') {
    $adminBase = '';
}

$siteBase = rtrim(str_replace('\\', '/', dirname($adminBase === '' ? '/' : $adminBase)), '/');
if ($siteBase === '.' || $siteBase === '/') {
    $siteBase = '';
}

$requestUri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestUri = is_string($requestUri) ? $requestUri : '/';

if ($adminBase !== '' && str_starts_with($requestUri, $adminBase)) {
    $adminPath = substr($requestUri, strlen($adminBase));
} elseif (str_starts_with($requestUri, '/admin')) {
    $adminPath = substr($requestUri, strlen('/admin'));
} else {
    $adminPath = $requestUri;
}

$adminPath = '/' . trim((string) $adminPath, '/');
if ($adminPath === '/' || $adminPath === '/index.php') {
    $adminPath = '/';
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header(
    "Content-Security-Policy: default-src 'self'; "
    . "img-src 'self' data: blob: https:; "
    . "style-src 'self' https://fonts.googleapis.com; "
    . "font-src 'self' https://fonts.gstatic.com; "
    . "script-src 'self'; connect-src 'self'; "
    . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
);
header('Cache-Control: no-store');

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        global $siteBase;
        $path = ltrim($path, '/');
        return ($siteBase === '' ? '' : $siteBase) . '/admin' . ($path === '' ? '/' : '/' . $path);
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string
    {
        global $siteBase;
        $path = ltrim($path, '/');
        return ($siteBase === '' ? '' : $siteBase) . '/' . $path;
    }
}

if (!function_exists('admin_asset')) {
    function admin_asset(string $path): string
    {
        return site_url('assets/admin/' . ltrim($path, '/'));
    }
}

if (!function_exists('admin_image_url')) {
    function admin_image_url(?string $path): string
    {
        $resolved = ImageUrl::resolve($path);

        return $resolved ?? site_url('assets/images/products/placeholder.svg');
    }
}

if (!function_exists('admin_money')) {
    function admin_money(mixed $value, string $currency = 'USD'): string
    {
        $amount = (float) $value;
        $symbol = strtoupper($currency) === 'USD' ? '$' : (strtoupper($currency) . ' ');

        return $symbol . number_format($amount, 2, '.', ',');
    }
}

if (!function_exists('admin_date')) {
    function admin_date(mixed $value, bool $withTime = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            $date = new DateTimeImmutable((string) $value);
        } catch (Exception) {
            return '—';
        }

        return $date->format($withTime ? 'M j, Y H:i' : 'M j, Y');
    }
}

if (!function_exists('admin_status_class')) {
    function admin_status_class(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'delivered', 'active' => 'is-success',
            'processing', 'shipped', 'pending' => 'is-warning',
            'cancelled', 'refunded', 'inactive' => 'is-danger',
            default => 'is-neutral',
        };
    }
}

if (!function_exists('admin_flash')) {
    function admin_flash(string $type, string $message): void
    {
        $_SESSION['admin_flashes'] ??= [];
        $_SESSION['admin_flashes'][] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('admin_take_flashes')) {
    /**
     * @return list<array{type: string, message: string}>
     */
    function admin_take_flashes(): array
    {
        $flashes = $_SESSION['admin_flashes'] ?? [];
        unset($_SESSION['admin_flashes']);

        return is_array($flashes) ? array_values(array_filter($flashes, 'is_array')) : [];
    }
}

if (!function_exists('admin_redirect')) {
    function admin_redirect(string $path, int $status = 303): never
    {
        header('Location: ' . admin_url($path), true, $status);
        exit;
    }
}

if (!function_exists('admin_csrf_field')) {
    function admin_csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Auth::csrfToken()) . '">';
    }
}

if (!function_exists('admin_view')) {
    /**
     * @param array<string, mixed> $data
     */
    function admin_view(string $view, array $data = []): void
    {
        $viewFile = __DIR__ . '/views/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new RuntimeException('Admin view not found: ' . $view);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        $currentAdmin = $GLOBALS['currentAdmin'] ?? null;
        $flashes = admin_take_flashes();
        $title = $data['title'] ?? 'Admin';
        $active = $data['active'] ?? '';
        require __DIR__ . '/views/layout.php';
    }
}

if (!function_exists('admin_error_page')) {
    function admin_error_page(int $status, string $message): void
    {
        http_response_code($status);
        admin_view('error', [
            'title' => 'Error',
            'active' => '',
            'status' => $status,
            'message' => $message,
        ]);
        exit;
    }
}

if (!function_exists('admin_post')) {
    function admin_post(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $default;
        return is_array($value) ? $value : $value;
    }
}

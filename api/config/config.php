<?php
declare(strict_types=1);

/**
 * RedStore API configuration.
 *
 * Values are read from real environment variables first (Render, Docker,
 * Apache SetEnv, etc.) and then from the project-root .env file for local
 * development. Secrets are never sent to the browser.
 */

if (!function_exists('redstore_load_env')) {
    function redstore_load_env(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '' || getenv($key) !== false || isset($_ENV[$key]) || isset($_SERVER[$key])) {
                continue;
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

if (!function_exists('redstore_env')) {
    function redstore_env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        return ($value === null || $value === false) ? $default : (string) $value;
    }
}

if (!function_exists('redstore_config')) {
    /**
     * @return array<string, mixed>
     */
    function redstore_config(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }

        $root = dirname(__DIR__, 2);
        redstore_load_env($root . '/.env');

        $config = [
            'app_env' => redstore_env('APP_ENV', 'production'),
            'root' => $root,
            'supabase_url' => rtrim((string) redstore_env('SUPABASE_URL', ''), '/'),
            'storage_bucket' => trim((string) redstore_env('SUPABASE_STORAGE_BUCKET', 'product-images'), '/'),
            'storage_public_url' => rtrim((string) redstore_env('SUPABASE_STORAGE_PUBLIC_URL', ''), '/'),
            'cors_allowed_origin' => trim((string) redstore_env('CORS_ALLOWED_ORIGIN', '')),
            'db' => [
                'url' => (string) redstore_env('DATABASE_URL', ''),
                'host' => (string) redstore_env('DB_HOST', ''),
                'port' => (string) redstore_env('DB_PORT', '5432'),
                'name' => (string) redstore_env('DB_NAME', 'postgres'),
                'user' => (string) redstore_env('DB_USER', 'postgres'),
                'password' => (string) redstore_env('DB_PASSWORD', ''),
                'sslmode' => (string) redstore_env('DB_SSLMODE', 'require'),
            ],
        ];

        return $config;
    }
}

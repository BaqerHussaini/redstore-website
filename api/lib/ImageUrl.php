<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

final class ImageUrl
{
    /**
     * Resolve a database image reference into a browser-usable URL.
     *
     * Supported values:
     *   - absolute http(s) URLs
     *   - root-relative URLs (/assets/...)
     *   - local asset paths (assets/images/... or ../assets/images/...)
     *   - Supabase Storage object paths (products/<slug>/main.webp)
     */
    public static function resolve(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        if (str_starts_with($path, '//')) {
            return 'https:' . $path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $path = preg_replace('#^(\.\./)+#', '', $path) ?? $path;
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'assets/')) {
            return '/' . $path;
        }

        $config = redstore_config();
        $bucket = $config['storage_bucket'] !== '' ? $config['storage_bucket'] : 'product-images';

        if (str_starts_with($path, $bucket . '/')) {
            $path = substr($path, strlen($bucket) + 1);
        }

        if ($config['storage_public_url'] !== '') {
            $base = rtrim($config['storage_public_url'], '/');
        } elseif ($config['supabase_url'] !== '') {
            $base = rtrim($config['supabase_url'], '/')
                . '/storage/v1/object/public/'
                . rawurlencode($bucket);
        } else {
            return null;
        }

        $segments = array_filter(explode('/', $path), static fn (string $part): bool => $part !== '');
        if ($segments === []) {
            return null;
        }

        return $base . '/' . implode('/', array_map('rawurlencode', $segments));
    }
}

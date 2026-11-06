<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

final class SupabaseStorage
{
    private string $baseUrl;
    private string $bucket;
    private string $serviceKey;

    public function __construct()
    {
        $config = redstore_config();
        $this->baseUrl = rtrim((string) $config['supabase_url'], '/');
        $this->bucket = trim((string) $config['storage_bucket'], '/');
        $this->serviceKey = (string) $config['supabase_service_role_key'];
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->bucket !== '' && $this->serviceKey !== '';
    }

    /**
     * @return array{ok: bool, status: int, body: string}
     */
    public function upload(string $objectPath, string $contents, string $contentType): array
    {
        $this->assertConfigured();
        $this->assertSafeObjectPath($objectPath);

        $url = $this->baseUrl
            . '/storage/v1/object/'
            . rawurlencode($this->bucket)
            . '/'
            . $this->encodePath($objectPath);

        return $this->request('POST', $url, $contents, [
            'Content-Type: ' . $contentType,
            'x-upsert: false',
        ]);
    }

    /**
     * @return array{ok: bool, status: int, body: string}
     */
    public function delete(string $objectPath): array
    {
        $this->assertConfigured();
        $this->assertSafeObjectPath($objectPath);

        $url = $this->baseUrl
            . '/storage/v1/object/'
            . rawurlencode($this->bucket)
            . '/'
            . $this->encodePath($objectPath);

        return $this->request('DELETE', $url, null, []);
    }

    public function isStoragePath(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $needle = '/storage/v1/object/public/' . $this->bucket . '/';
            $position = strpos($value, $needle);
            return $position !== false;
        }

        if (str_starts_with($value, '/') || str_starts_with($value, 'assets/') || str_starts_with($value, '../')) {
            return false;
        }

        return !str_starts_with($value, $this->bucket . '/') || strlen($value) > strlen($this->bucket) + 1;
    }

    public function objectPathFromStoredValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $needle = '/storage/v1/object/public/' . $this->bucket . '/';
            $position = strpos($value, $needle);
            if ($position === false) {
                return null;
            }
            $value = substr($value, $position + strlen($needle));
            $value = rawurldecode($value);
        }

        $value = ltrim($value, '/');
        if (str_starts_with($value, $this->bucket . '/')) {
            $value = substr($value, strlen($this->bucket) + 1);
        }

        if ($value === '' || str_starts_with($value, 'assets/') || str_starts_with($value, '../')) {
            return null;
        }

        return $value;
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new ApiException(
                503,
                'storage_not_configured',
                'Supabase Storage is not configured on this server. Set SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY.'
            );
        }
    }

    private function assertSafeObjectPath(string $objectPath): void
    {
        $objectPath = ltrim($objectPath, '/');
        if (
            $objectPath === ''
            || str_contains($objectPath, '..')
            || str_contains($objectPath, "\0")
            || str_contains($objectPath, '\\')
            || preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._/-]*$#', $objectPath) !== 1
        ) {
            throw new ApiException(422, 'invalid_storage_path', 'The image storage path is not valid.');
        }
    }

    private function encodePath(string $path): string
    {
        $segments = array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== '');
        return implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * @param list<string> $extraHeaders
     * @return array{ok: bool, status: int, body: string}
     */
    private function request(string $method, string $url, ?string $body, array $extraHeaders): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException(503, 'storage_unavailable', 'The PHP cURL extension is required for image uploads.');
        }

        $headers = array_merge([
            'Authorization: Bearer ' . $this->serviceKey,
            'apikey: ' . $this->serviceKey,
            'Accept: application/json',
        ], $extraHeaders);

        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiException(503, 'storage_unavailable', 'The image storage request could not be started.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($responseBody === false) {
            error_log('[RedStore Storage] cURL error: ' . $error);
            return ['ok' => false, 'status' => 0, 'body' => ''];
        }

        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }
}

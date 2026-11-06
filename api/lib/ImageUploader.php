<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/Input.php';

final class ImageUploader
{
    public const MAX_BYTES = 5242880;

    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /**
     * Normalise a PHP $_FILES entry that may contain one or many uploads.
     *
     * @param mixed $entry
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public static function normalize(mixed $entry): array
    {
        if (!is_array($entry) || !isset($entry['tmp_name'])) {
            return [];
        }

        if (!is_array($entry['tmp_name'])) {
            return [[
                'name' => (string) ($entry['name'] ?? ''),
                'type' => (string) ($entry['type'] ?? ''),
                'tmp_name' => (string) ($entry['tmp_name'] ?? ''),
                'error' => (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($entry['size'] ?? 0),
            ]];
        }

        $files = [];
        foreach (array_keys($entry['tmp_name']) as $key) {
            $error = (int) ($entry['error'][$key] ?? UPLOAD_ERR_NO_FILE);
            $tmpName = (string) ($entry['tmp_name'][$key] ?? '');
            // Keep entries that failed before PHP created a temp file (for
            // example upload_max_filesize) so validate() can report the real
            // error instead of silently ignoring the upload.
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $files[] = [
                'name' => (string) ($entry['name'][$key] ?? ''),
                'type' => (string) ($entry['type'][$key] ?? ''),
                'tmp_name' => $tmpName,
                'error' => $error,
                'size' => (int) ($entry['size'][$key] ?? 0),
            ];
        }

        return $files;
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return array{tmp_name: string, mime: string, extension: string, size: int}
     */
    public static function validate(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ApiException(422, 'upload_failed', self::uploadErrorMessage((int) $file['error']));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new ApiException(422, 'upload_failed', 'The uploaded file could not be verified.');
        }

        $size = (int) ($file['size'] ?? (@filesize($tmpName) ?: 0));
        if ($size <= 0) {
            throw new ApiException(422, 'upload_empty', 'The uploaded file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new ApiException(422, 'upload_too_large', 'Each image must be 5 MB or smaller.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpName);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw new ApiException(
                422,
                'upload_type',
                'Unsupported image type. Use JPEG, PNG, WebP or AVIF.'
            );
        }

        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $info = @getimagesize($tmpName);
            if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1) {
                throw new ApiException(422, 'upload_invalid', 'The uploaded file is not a valid image.');
            }
        }

        return [
            'tmp_name' => $tmpName,
            'mime' => $mime,
            'extension' => self::MIME_EXTENSIONS[$mime],
            'size' => $size,
        ];
    }

    public static function objectPath(string $productSlug, string $kind, string $extension): string
    {
        $slug = Input::slugify($productSlug, 150);
        $kind = preg_replace('/[^a-z0-9-]/', '', strtolower($kind)) ?: 'image';
        $random = bin2hex(random_bytes(12));

        return 'products/' . $slug . '/' . $kind . '-' . $random . '.' . $extension;
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image is too large for this server.',
            UPLOAD_ERR_PARTIAL => 'The image was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No image file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image.',
            UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.',
            default => 'The image upload failed.',
        };
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';

final class Input
{
    /**
     * @param array<string, mixed> $source
     */
    public static function string(array $source, string $key, int $maxLength, bool $required = true): ?string
    {
        $value = $source[$key] ?? '';
        if (is_array($value)) {
            throw new ApiException(422, 'invalid_' . $key, 'The ' . self::label($key) . ' value is not valid.');
        }

        $value = trim(str_replace("\0", '', (string) $value));
        if ($value === '') {
            if ($required) {
                throw new ApiException(422, 'invalid_' . $key, 'The ' . self::label($key) . ' field is required.');
            }
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new ApiException(
                422,
                'invalid_' . $key,
                sprintf('The %s must be %d characters or fewer.', self::label($key), $maxLength)
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function integer(array $source, string $key, int $min, int $max, int $default = 0): int
    {
        $value = $source[$key] ?? '';
        if (is_array($value) || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);

        if ($filtered === false) {
            throw new ApiException(
                422,
                'invalid_' . $key,
                sprintf('The %s must be a whole number between %d and %d.', self::label($key), $min, $max)
            );
        }

        return (int) $filtered;
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function money(array $source, string $key, bool $required = true): ?string
    {
        $value = $source[$key] ?? '';
        if (is_array($value)) {
            throw new ApiException(422, 'invalid_' . $key, 'The ' . self::label($key) . ' value is not valid.');
        }

        $value = trim((string) $value);
        if ($value === '') {
            if ($required) {
                throw new ApiException(422, 'invalid_' . $key, 'The ' . self::label($key) . ' field is required.');
            }
            return null;
        }

        $value = str_replace([',', ' '], '', $value);
        if (preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $value) !== 1) {
            throw new ApiException(
                422,
                'invalid_' . $key,
                'The ' . self::label($key) . ' must be a positive amount with up to two decimal places.'
            );
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function boolean(array $source, string $key): bool
    {
        $value = $source[$key] ?? false;
        if (is_array($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Read and decode a JSON request body.
     *
     * @return array<string, mixed>
     */
    public static function jsonBody(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === false || trim($raw) === '') {
                return [];
            }
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ApiException(400, 'invalid_json', 'The request body is not valid JSON.');
            }
            if (!is_array($decoded)) {
                throw new ApiException(400, 'invalid_json', 'The JSON request body must be an object.');
            }
            return $decoded;
        }

        return is_array($_POST) ? $_POST : [];
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function uuid(array $source, string $key, bool $required = false): ?string
    {
        $value = $source[$key] ?? '';
        if (is_array($value)) {
            throw new ApiException(422, 'invalid_' . $key, 'The ' . self::label($key) . ' is not valid.');
        }

        $value = trim((string) $value);
        if ($value === '') {
            if ($required) {
                throw new ApiException(422, 'invalid_' . $key, 'The ' . self::label($key) . ' is required.');
            }
            return null;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new ApiException(422, 'invalid_' . $key, 'The ' . self::label($key) . ' is not valid.');
        }

        return strtolower($value);
    }

    public static function slugify(string $value, int $maxLength = 180): string
    {
        $value = trim($value);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = substr($value, 0, $maxLength);
        $value = rtrim($value, '-');

        return $value === '' ? 'item' : $value;
    }

    public static function validSlug(string $slug): bool
    {
        return strlen($slug) <= 180
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }

    public static function validateEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'invalid_email', 'Enter a valid email address.');
        }

        return $email;
    }

    private static function label(string $key): string
    {
        return str_replace('_', ' ', $key);
    }
}

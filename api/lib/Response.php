<?php
declare(strict_types=1);

final class Response
{
    /**
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, mixed>|null $meta
     */
    public static function success(array $data, ?array $meta = null, int $status = 200): void
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        self::send($payload, $status);
    }

    public static function error(
        int $status,
        string $code,
        string $message,
        ?array $details = null
    ): void {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        self::send([
            'success' => false,
            'error' => $error,
        ], $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }
}

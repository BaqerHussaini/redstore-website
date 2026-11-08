<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CustomerAuth.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';

/**
 * Storefront account endpoints.
 */
final class AuthController
{
    /**
     * POST /api/auth/register
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    public function register(array $input): array
    {
        $fullName = Input::string($input, 'full_name', 120, false);
        $email = Input::string($input, 'email', 255, true) ?? '';
        $password = $this->password($input);

        $result = CustomerAuth::register($fullName, $email, $password);
        if (!$result['ok']) {
            throw $this->failure($result);
        }

        return [
            [
                'user' => $result['user'] ?? null,
                'csrf_token' => CustomerAuth::csrfToken(),
            ],
            null,
        ];
    }

    /**
     * POST /api/auth/login
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    public function login(array $input): array
    {
        $email = Input::string($input, 'email', 255, true) ?? '';
        $password = $this->password($input);

        $result = CustomerAuth::login($email, $password);
        if (!$result['ok']) {
            throw $this->failure($result);
        }

        return [
            [
                'user' => $result['user'] ?? null,
                'csrf_token' => CustomerAuth::csrfToken(),
            ],
            null,
        ];
    }

    /**
     * POST /api/auth/logout
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    public function logout(): array
    {
        CustomerAuth::logout();

        return [['ok' => true], null];
    }

    /**
     * GET /api/auth/me
     *
     * Returns `user: null` for guests. The CSRF token is always included so the
     * storefront can authenticate cart mutations without an extra request.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    public function me(): array
    {
        return [
            [
                'user' => CustomerAuth::user(),
                'csrf_token' => CustomerAuth::csrfToken(),
            ],
            null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function password(array $input): string
    {
        $password = $input['password'] ?? '';
        if (is_array($password)) {
            throw new ApiException(422, 'invalid_password', 'The password is not valid.');
        }

        return (string) $password;
    }

    /**
     * @param array{ok: bool, code?: string, message: string, retry_after?: int} $result
     */
    private function failure(array $result): ApiException
    {
        $code = $result['code'] ?? 'auth_failed';
        $status = match ($code) {
            'email_taken' => 409,
            'weak_password', 'invalid_password', 'invalid_email', 'invalid_full_name' => 422,
            'too_many_attempts' => 429,
            default => 401,
        };

        if ($code === 'too_many_attempts') {
            header('Retry-After: ' . (string) ($result['retry_after'] ?? 900));
        }

        return new ApiException($status, $code, $result['message']);
    }
}

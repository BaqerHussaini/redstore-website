<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/LoginAttempt.php';

/**
 * Storefront customer authentication.
 *
 * Uses its own session cookie (`redstore_customer`) so a customer login can
 * never be confused with an administrator session (`redstore_admin`). Only
 * accounts with role = "user" are accepted here.
 */
final class CustomerAuth
{
    public const SESSION_NAME = 'redstore_customer';
    private const SESSION_LIFETIME = 1209600;
    private const REGENERATE_INTERVAL = 1800;
    private const LOGIN_WINDOW = 900;
    private const EMAIL_MAX_FAILURES = 5;
    private const IP_MAX_FAILURES = 20;
    private const PASSWORD_MIN = 8;
    private const PASSWORD_MAX = 200;

    private static bool $started = false;
    /** @var array<string, mixed>|null */
    private static ?array $cachedUser = null;

    public static function startSession(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $config = redstore_config();
        $secure = self::cookieSecure($config);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) self::SESSION_LIFETIME);
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::$started = true;

        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        }
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            self::rotateCsrf();
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function cookieSecure(array $config): bool
    {
        $override = strtolower(trim((string) ($config['session_cookie_secure'] ?? '')));
        if (in_array($override, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($override, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ($forwarded === 'https') {
            return true;
        }

        return strtolower((string) ($config['app_env'] ?? '')) === 'production'
            && trim((string) ($config['session_cookie_secure'] ?? '')) === '';
    }

    /**
     * @return array{ok: bool, code?: string, message: string, user?: array<string, mixed>, retry_after?: int}
     */
    public static function login(string $email, string $password): array
    {
        self::startSession();

        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'code' => 'invalid_credentials', 'message' => 'Invalid email or password.'];
        }
        if ($password === '' || strlen($password) > self::PASSWORD_MAX) {
            return ['ok' => false, 'code' => 'invalid_credentials', 'message' => 'Invalid email or password.'];
        }

        $db = redstore_db();
        $attempts = new LoginAttempt($db);
        $ip = self::clientIp();
        $failures = $attempts->recentFailures($email, $ip, self::LOGIN_WINDOW);

        if ($failures['email'] >= self::EMAIL_MAX_FAILURES || $failures['ip'] >= self::IP_MAX_FAILURES) {
            return [
                'ok' => false,
                'code' => 'too_many_attempts',
                'message' => 'Too many failed login attempts. Please try again in a few minutes.',
                'retry_after' => self::LOGIN_WINDOW,
            ];
        }

        $users = new User($db);
        $row = $users->findByEmail($email);

        $valid = $row !== null && password_verify($password, (string) $row['password_hash']);
        if (!$valid || (string) $row['role'] !== 'user' || !(bool) $row['is_active']) {
            $attempts->record($email, $ip, self::userAgent(), false);
            $attempts->prune();
            return ['ok' => false, 'code' => 'invalid_credentials', 'message' => 'Invalid email or password.'];
        }

        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            if ($newHash !== false) {
                $users->updateCustomerPassword((string) $row['id'], $newHash);
            }
        }

        $attempts->clearFailures($email, $ip);
        $attempts->record($email, $ip, self::userAgent(), true);
        $attempts->prune();

        self::establishSession($row);

        return [
            'ok' => true,
            'message' => 'Signed in successfully.',
            'user' => self::publicUser($row),
        ];
    }

    /**
     * @return array{ok: bool, code?: string, message: string, user?: array<string, mixed>}
     */
    public static function register(?string $fullName, string $email, string $password): array
    {
        self::startSession();

        $fullName = $fullName !== null ? trim(str_replace("\0", '', $fullName)) : null;
        if ($fullName === '') {
            $fullName = null;
        }
        if ($fullName !== null && mb_strlen($fullName) > 120) {
            return ['ok' => false, 'code' => 'invalid_full_name', 'message' => 'The name must be 120 characters or fewer.'];
        }

        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'code' => 'invalid_email', 'message' => 'Enter a valid email address.'];
        }

        $passwordLength = strlen($password);
        if ($passwordLength < self::PASSWORD_MIN) {
            return [
                'ok' => false,
                'code' => 'weak_password',
                'message' => 'The password must be at least ' . self::PASSWORD_MIN . ' characters long.',
            ];
        }
        if ($passwordLength > self::PASSWORD_MAX) {
            return ['ok' => false, 'code' => 'invalid_password', 'message' => 'The password is too long.'];
        }

        $db = redstore_db();
        $users = new User($db);

        if ($users->emailExists($email)) {
            return ['ok' => false, 'code' => 'email_taken', 'message' => 'An account with that email already exists.'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            return ['ok' => false, 'code' => 'server_error', 'message' => 'The account could not be created.'];
        }

        try {
            $id = $users->createCustomer($email, $hash, $fullName);
        } catch (PDOException $exception) {
            if (($exception->getCode() ?? '') === '23505') {
                return ['ok' => false, 'code' => 'email_taken', 'message' => 'An account with that email already exists.'];
            }
            throw $exception;
        }

        $row = $users->findAuthRowById($id);
        if ($row === null) {
            return ['ok' => false, 'code' => 'server_error', 'message' => 'The account could not be created.'];
        }

        self::establishSession($row);

        return [
            'ok' => true,
            'message' => 'Account created successfully.',
            'user' => self::publicUser($row),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function establishSession(array $row): void
    {
        session_regenerate_id(true);
        self::$cachedUser = null;
        $_SESSION['customer_user_id'] = (string) $row['id'];
        $_SESSION['_regen'] = time();
        $_SESSION['_created'] = time();
        self::rotateCsrf();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        self::startSession();

        $id = $_SESSION['customer_user_id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        if (self::$cachedUser !== null && (string) self::$cachedUser['id'] === $id) {
            return self::$cachedUser;
        }

        $users = new User(redstore_db());
        $row = $users->findPublicById($id);

        if ($row === null || (string) $row['role'] !== 'user' || !(bool) $row['is_active']) {
            self::logout();
            return null;
        }

        $lastRegenerated = (int) ($_SESSION['_regen'] ?? 0);
        if ($lastRegenerated === 0 || time() - $lastRegenerated >= self::REGENERATE_INTERVAL) {
            session_regenerate_id(true);
            $_SESSION['_regen'] = time();
            self::rotateCsrf();
        }

        self::$cachedUser = $row;
        return self::publicUser($row);
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            throw new ApiException(401, 'unauthorized', 'Please sign in to use your saved cart.');
        }

        return $user;
    }

    public static function logout(): void
    {
        self::startSession();
        self::$cachedUser = null;

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?: '',
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }

        session_destroy();
        self::$started = false;
    }

    public static function csrfToken(): string
    {
        self::startSession();

        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            self::rotateCsrf();
        }

        return (string) $_SESSION['csrf_token'];
    }

    public static function rotateCsrf(): void
    {
        self::startSession();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function validateCsrf(?string $token): bool
    {
        self::startSession();
        $stored = $_SESSION['csrf_token'] ?? null;

        return is_string($stored)
            && is_string($token)
            && $token !== ''
            && hash_equals($stored, $token);
    }

    public static function requireCsrf(?string $token): void
    {
        if (!self::validateCsrf($token)) {
            throw new ApiException(403, 'csrf_failed', 'The security token is missing or invalid. Please refresh the page and try again.');
        }
    }

    public static function clientIp(): ?string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public static function userAgent(): ?string
    {
        $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $agent === '' ? null : mb_substr($agent, 0, 255);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function publicUser(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'email' => (string) $row['email'],
            'full_name' => $row['full_name'] !== null ? (string) $row['full_name'] : null,
            'role' => (string) $row['role'],
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}

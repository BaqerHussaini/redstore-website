<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/LoginAttempt.php';

final class Auth
{
    public const SESSION_NAME = 'redstore_admin';
    private const SESSION_LIFETIME = 28800;
    private const REGENERATE_INTERVAL = 1800;
    private const LOGIN_WINDOW = 900;
    private const EMAIL_MAX_FAILURES = 5;
    private const IP_MAX_FAILURES = 20;

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
        if ($forwarded !== '' && $forwarded === 'https') {
            return true;
        }

        return strtolower((string) ($config['app_env'] ?? '')) === 'production'
            && self::cookieSecureOverrideLooksUnset($config);
    }

    /**
     * APP_ENV=production alone is not enough on a plain-HTTP local test host,
     * but it is the safe default behind Render's TLS proxy. An explicit
     * SESSION_COOKIE_SECURE value always wins.
     *
     * @param array<string, mixed> $config
     */
    private static function cookieSecureOverrideLooksUnset(array $config): bool
    {
        return trim((string) ($config['session_cookie_secure'] ?? '')) === '';
    }

    /**
     * @return array{ok: bool, message: string, user?: array<string, mixed>, retry_after?: int}
     */
    public static function login(string $email, string $password): array
    {
        self::startSession();

        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Invalid email or password.'];
        }
        if ($password === '' || strlen($password) > 200) {
            return ['ok' => false, 'message' => 'Invalid email or password.'];
        }

        $db = redstore_db();
        $attempts = new LoginAttempt($db);
        $ip = self::clientIp();
        $failures = $attempts->recentFailures($email, $ip, self::LOGIN_WINDOW);

        if ($failures['email'] >= self::EMAIL_MAX_FAILURES || $failures['ip'] >= self::IP_MAX_FAILURES) {
            return [
                'ok' => false,
                'message' => 'Too many failed login attempts. Please try again in a few minutes.',
                'retry_after' => self::LOGIN_WINDOW,
            ];
        }

        $users = new User($db);
        $row = $users->findByEmail($email);

        $valid = $row !== null && password_verify($password, (string) $row['password_hash']);
        if (!$valid || (string) $row['role'] !== 'admin' || !(bool) $row['is_active']) {
            $attempts->record($email, $ip, self::userAgent(), false);
            $attempts->prune();
            return ['ok' => false, 'message' => 'Invalid email or password.'];
        }

        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            if ($newHash !== false) {
                $users->updatePassword((string) $row['id'], $newHash);
            }
        }

        $attempts->clearFailures($email, $ip);
        $attempts->record($email, $ip, self::userAgent(), true);
        $attempts->prune();

        session_regenerate_id(true);
        self::$cachedUser = null;
        $_SESSION['admin_user_id'] = (string) $row['id'];
        $_SESSION['_regen'] = time();
        $_SESSION['_created'] = time();
        self::rotateCsrf();

        return [
            'ok' => true,
            'message' => 'Signed in successfully.',
            'user' => self::publicUser($row),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        self::startSession();

        $id = $_SESSION['admin_user_id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        if (self::$cachedUser !== null && (string) self::$cachedUser['id'] === $id) {
            return self::$cachedUser;
        }

        $users = new User(redstore_db());
        $row = $users->findPublicById($id);

        if (
            $row === null
            || (string) $row['role'] !== 'admin'
            || !(bool) $row['is_active']
        ) {
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
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireAdmin(): array
    {
        $user = self::user();
        if ($user === null) {
            throw new ApiException(401, 'unauthorized', 'Authentication is required.');
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
     * @return array{ok: bool, message: string}
     */
    public static function updateOwnProfile(string $userId, string $fullName, string $email): array
    {
        $fullName = trim($fullName);
        $email = strtolower(trim($email));

        if (mb_strlen($fullName) > 150) {
            return ['ok' => false, 'message' => 'The name must be 150 characters or fewer.'];
        }
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Enter a valid email address.'];
        }

        $users = new User(redstore_db());
        $row = $users->findAuthRowById($userId);
        if ($row === null || (string) $row['role'] !== 'admin' || !(bool) $row['is_active']) {
            return ['ok' => false, 'message' => 'The account could not be updated.'];
        }
        if ($users->emailExists($email, $userId)) {
            return ['ok' => false, 'message' => 'That email address is already used by another account.'];
        }

        if (!$users->updateProfile($userId, $email, $fullName)) {
            return ['ok' => false, 'message' => 'The account could not be updated.'];
        }

        self::$cachedUser = null;
        self::rotateCsrf();

        return ['ok' => true, 'message' => 'Administrator profile updated.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function changeOwnPassword(
        string $userId,
        string $currentPassword,
        string $newPassword,
        string $confirmation
    ): array {
        if ($newPassword !== $confirmation) {
            return ['ok' => false, 'message' => 'The new passwords do not match.'];
        }
        if (strlen($newPassword) < 12) {
            return ['ok' => false, 'message' => 'The new password must be at least 12 characters long.'];
        }
        if (strlen($newPassword) > 200) {
            return ['ok' => false, 'message' => 'The new password is too long.'];
        }
        if ($currentPassword === '' || strlen($currentPassword) > 200) {
            return ['ok' => false, 'message' => 'Enter your current password.'];
        }

        $users = new User(redstore_db());
        $row = $users->findAuthRowById($userId);
        if ($row === null || (string) $row['role'] !== 'admin' || !(bool) $row['is_active']) {
            return ['ok' => false, 'message' => 'The account could not be updated.'];
        }
        if (!password_verify($currentPassword, (string) $row['password_hash'])) {
            return ['ok' => false, 'message' => 'The current password is incorrect.'];
        }
        if (password_verify($newPassword, (string) $row['password_hash'])) {
            return ['ok' => false, 'message' => 'Choose a password that is different from the current one.'];
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($hash === false || !$users->updatePassword($userId, $hash)) {
            return ['ok' => false, 'message' => 'The password could not be updated.'];
        }

        session_regenerate_id(true);
        $_SESSION['_regen'] = time();
        self::rotateCsrf();

        return ['ok' => true, 'message' => 'Password updated successfully.'];
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

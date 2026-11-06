#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Create the first RedStore administrator, or reset an existing admin's
 * password with --update-password.
 *
 * Usage:
 *   php scripts/create-admin.php --email=admin@example.com --name="Store Admin"
 *   php scripts/create-admin.php --email=admin@example.com --password="a-long-password"
 *   php scripts/create-admin.php --email=admin@example.com --update-password
 *
 * Omit --password to be prompted without echoing the value.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/api/config/database.php';
require_once dirname(__DIR__) . '/api/models/User.php';

$options = getopt('', ['email:', 'name::', 'password::', 'update-password']);

$email = strtolower(trim((string) ($options['email'] ?? '')));
$name = trim((string) ($options['name'] ?? 'Store Administrator'));
$password = (string) ($options['password'] ?? '');
$updatePassword = array_key_exists('update-password', $options);

$fail = static function (string $message): never {
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
};

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
    $fail('Provide a valid email with --email=address@example.com.');
}

if ($name === '') {
    $name = 'Store Administrator';
}
if (mb_strlen($name) > 150) {
    $fail('The name must be 150 characters or fewer.');
}

$readHidden = static function (string $prompt): string {
    if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
        shell_exec('stty -echo');
        echo $prompt;
        $value = fgets(STDIN);
        shell_exec('stty echo');
        echo PHP_EOL;

        return $value === false ? '' : rtrim($value, "\r\n");
    }

    echo $prompt;
    $value = fgets(STDIN);

    return $value === false ? '' : rtrim($value, "\r\n");
};

if ($password === '') {
    $password = $readHidden('Password (minimum 12 characters): ');
    $confirmation = $readHidden('Confirm password: ');
    if ($password !== $confirmation) {
        $fail('The passwords do not match.');
    }
}

if (strlen($password) < 12) {
    $fail('The password must be at least 12 characters long.');
}
if (strlen($password) > 200) {
    $fail('The password must be 200 characters or fewer.');
}

try {
    $users = new User(redstore_db());
    $existing = $users->findByEmail($email);

    if ($existing !== null) {
        if ((string) $existing['role'] !== 'admin') {
            $fail('That email belongs to a customer account, not an administrator.');
        }
        if (!$updatePassword) {
            $fail('An administrator with that email already exists. Use --update-password to reset it.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false || !$users->updatePassword((string) $existing['id'], $hash)) {
            $fail('The administrator password could not be updated.');
        }

        echo "Updated administrator password for {$email}.\n";
        exit(0);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        $fail('The password could not be hashed.');
    }

    $id = $users->createAdmin($email, $hash, $name);
    echo "Created administrator {$email} ({$id}).\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Error: " . $exception->getMessage() . "\n");
    exit(1);
}

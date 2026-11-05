<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('redstore_db_dsn')) {
    /**
     * Build a PDO PostgreSQL DSN from DATABASE_URL or the DB_* variables.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    function redstore_db_dsn(): array
    {
        $config = redstore_config();
        $db = $config['db'];

        if ($db['url'] !== '') {
            $parts = parse_url($db['url']);
            if ($parts === false || !isset($parts['host'])) {
                throw new RuntimeException('DATABASE_URL is not a valid PostgreSQL URL.');
            }

            $host = $parts['host'];
            $port = isset($parts['port']) ? (string) $parts['port'] : '5432';
            $name = isset($parts['path']) ? ltrim($parts['path'], '/') : 'postgres';
            $user = isset($parts['user']) ? urldecode($parts['user']) : 'postgres';
            $password = isset($parts['pass']) ? urldecode($parts['pass']) : '';
            $sslmode = $db['sslmode'];

            if (isset($parts['query'])) {
                parse_str($parts['query'], $query);
                if (isset($query['sslmode']) && $query['sslmode'] !== '') {
                    $sslmode = (string) $query['sslmode'];
                }
            }

            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=10',
                $host,
                $port,
                $name,
                $sslmode
            );

            return [$dsn, $user, $password];
        }

        if ($db['host'] === '') {
            throw new RuntimeException('Database configuration is missing. Set DATABASE_URL or DB_HOST.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=10',
            $db['host'],
            $db['port'] !== '' ? $db['port'] : '5432',
            $db['name'] !== '' ? $db['name'] : 'postgres',
            $db['sslmode'] !== '' ? $db['sslmode'] : 'require'
        );

        return [$dsn, $db['user'], $db['password']];
    }
}

if (!function_exists('redstore_db')) {
    function redstore_db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        [$dsn, $user, $password] = redstore_db_dsn();

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 10,
            ]);
        } catch (PDOException $exception) {
            error_log('[RedStore API] Database connection failed: ' . $exception->getMessage());
            throw new RuntimeException('Database connection failed.');
        }

        return $pdo;
    }
}

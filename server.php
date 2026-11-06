<?php
declare(strict_types=1);

/**
 * Local development router for PHP's built-in server:
 *   php -S 127.0.0.1:8080 server.php
 *
 * Apache uses the .htaccess files instead; this file is not part of the
 * production request path.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? $uri : '/';

// Keep the development server's exposure in line with the production
// .htaccess rules: never serve dotfiles, backend source, SQL, or tooling.
$blockedPatterns = [
    '#(^|/)\.#',
    '#^/(database|scripts|docker)(/|$)#',
    '#^/(Dockerfile|docker-compose\.yml|render\.yaml|server\.php|README\.md)$#',
    '#^/api/(config|controllers|models|routes|lib)(/|$)#',
    '#^/admin/(bootstrap\.php|views|lib|models|controllers)(/|$)#',
];
foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $uri) === 1) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        return true;
    }
}

if (str_starts_with($uri, '/api')) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/api/index.php';
    require __DIR__ . '/api/index.php';
    return true;
}

if (str_starts_with($uri, '/admin')) {
    if (
        $uri === '/admin'
        || $uri === '/admin/'
        || (str_starts_with($uri, '/admin/') && !is_file(__DIR__ . $uri))
    ) {
        $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/admin/index.php';
        require __DIR__ . '/admin/index.php';
        return true;
    }

    if (preg_match('#^/admin/(bootstrap\.php|views|lib|models|controllers)(/|$)#', $uri) === 1) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        return true;
    }
}

if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

if ($uri === '/') {
    readfile(__DIR__ . '/index.html');
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
return true;

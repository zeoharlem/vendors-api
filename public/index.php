<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

try {
    $app = ApplicationFactory::create($root);
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $basePath = '/vendors-api';

    if ($requestUri === $basePath) {
        $requestUri = '/';
    } elseif (str_starts_with($requestUri, $basePath . '/')) {
        $requestUri = substr($requestUri, strlen($basePath));
    }

    // Remove trailing slash, except for the root path.
    if ($requestUri !== '/') {
        $requestUri = rtrim($requestUri, '/');
    }

    $response = $app->handle($requestUri);
    $response->send();
} catch (Throwable $exception) {
    error_log((string) $exception);

    $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    $payload = [
        'success' => false,
        'error' => [
            'code' => 'INTERNAL_SERVER_ERROR',
            'message' => 'An unexpected error occurred.',
        ],
    ];

    if ($debug) {
        $payload['error']['debug'] = $exception->getMessage();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

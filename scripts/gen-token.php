<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Load environment variables if your project uses phpdotenv.
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Shared\Security\JwtService;

$jwtService = new JwtService(
    (string) ($_ENV['JWT_SECRET'] ?? ''),
    (string) ($_ENV['JWT_ISSUER'] ?? 'vendors-api'),
    (string) ($_ENV['JWT_AUDIENCE'] ?? 'vendors-api-clients'),
    (int) ($_ENV['JWT_TTL'] ?? 900),
);

// Replace this with a real user ID if your service expects one.
$token = $jwtService->issueAccessToken('user','family-id');

echo $token . PHP_EOL;

<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Shared\Security\JwtService;
use Dotenv\Dotenv;


Dotenv::createImmutable(__DIR__)->safeLoad();

try {
    $jwtService = new JwtService(
        secret: (string) ($_ENV['JWT_SECRET'] ?? ''),
        issuer: (string) ($_ENV['JWT_ISSUER'] ?? 'vendors-api'),
        audience: (string) (
            $_ENV['JWT_AUDIENCE'] ?? 'vendors-api-clients'
        ),
        ttl: (int) ($_ENV['JWT_TTL'] ?? 900),
    );

    // Simulate a user who has successfully logged in.
    // Simulate a user who has successfully logged in.
    $token = $jwtService->issueAccessToken('test-user-123');

    echo "PASS: Token issued.\n";

    // DEBUG: Inspect the JWT payload.
    $parts = explode('.', $token);

    $payload = json_decode(
        base64_decode(strtr($parts[1], '-_', '+/')),
        true
    );

    echo "JWT payload:\n";
    var_export($payload);
    echo PHP_EOL;

    // Validate the token we just issued.
    $claims = $jwtService->validate($token);

    echo "PASS: Token validated.\n";
    echo "Subject: {$claims->sub}\n";
    echo "token: {$token}\n";
    echo "Issuer: {$claims->iss}\n";
    echo "Expires at: {$claims->exp}\n";

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "FAIL:\n " . $exception . PHP_EOL
    );

    exit(1);
}

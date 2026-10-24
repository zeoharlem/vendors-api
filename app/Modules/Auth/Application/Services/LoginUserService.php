<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Infrastructure\Models\User;
use App\Shared\Security\JwtService;
use RuntimeException;

final class LoginUserService
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly RefreshTokenService $refreshTokenService,
    ) {
    }

    /**
     * Authenticate a user and issue an access token.
     *
     * @param array{
     *     email: string,
     *     password: string
     * } $data
     *
     * @return array{
     *     access_token: string,
     *     token_type: string,
     *     expires_in: int,
     *     user: array{
     *         id: string,
     *         email: string,
     *         first_name: string,
     *         last_name: string,
     *         status: mixed
     *     }
     * }
     */
    public function login(array $data): array
    {
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';

        if ($email === '' || !is_string($password) || $password === '') {
            throw new RuntimeException('Invalid email or password.', );
        }

        $user = User::findFirst(
            [
                'conditions' => 'LOWER(email) = :email:',
                'bind' => ['email' => $email],
            ]
        );

        // Use the same message for an unknown email and a wrong password.
        if (
            $user === false
            || $user === null
            || !is_string($user->password_hash)
            || !password_verify($password, $user->password_hash)
        ) {
            throw new RuntimeException('Invalid email or password.',);
        }

        $userId = (string) $user->id;

        if ($userId === '') {
            throw new RuntimeException('Unable to authenticate this account.',);
        }

        $accessToken = $this->jwtService->issueAccessToken($userId);
        $refreshToken = $this->refreshTokenService->issue($userId);

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'refresh_token' => $refreshToken,
            'user' => [
                'id' => $userId,
                'email' => (string) $user->email,
                'first_name' => (string) $user->first_name,
                'last_name' => (string) $user->last_name,
                'status' => $user->status,
            ],
        ];
    }
}
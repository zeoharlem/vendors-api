<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Shared\Security\JwtService;
use PDO;
use RuntimeException;
use Throwable;

final class RefreshTokenService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JwtService $jwtService,
        private readonly int $refreshTokenTtl = 2592000,
    ) {
        if ($this->refreshTokenTtl < 1) {
            throw new RuntimeException('Refresh token TTL must be positive.');
        }
    }

    /**
     * Issue the first refresh token for a login session.
     */
    public function issue(string $userId): string
    {
        $token = $this->generateToken();

        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO refresh_tokens (
                    user_id,
                    token_hash,
                    expires_at
                )
                VALUES (
                    :user_id,
                    :token_hash,
                    NOW() + (:ttl * INTERVAL '1 second')
                )
            SQL
        );

        $statement->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'ttl' => $this->refreshTokenTtl,
        ]);

        return $token;
    }

    /**
     * Consume a refresh token and issue its replacement.
     *
     * A consumed token being presented again revokes its entire family.
     */
    public function rotate(string $presentedToken): array
    {
        if ($presentedToken === '' || strlen($presentedToken) > 512) {
            throw new RuntimeException('Invalid refresh token.');
        }

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                    SELECT
                        id,
                        user_id,
                        family_id,
                        expires_at,
                        consumed_at,
                        revoked_at,
                        (expires_at > NOW()) AS is_unexpired
                    FROM refresh_tokens
                    WHERE token_hash = :token_hash
                    FOR UPDATE
                SQL
            );

            $statement->execute(['token_hash' => hash('sha256', $presentedToken),]);

            $current = $statement->fetch(PDO::FETCH_ASSOC);

            if ($current === false) {
                throw new RuntimeException('Invalid refresh token.');
            }

            /*
             * A previously consumed token has been reused.
             * Revoke the family and commit that revocation before returning
             * an error to the caller.
             */
            if ($current['consumed_at'] !== null) {
                $revokeFamily = $this->pdo->prepare(
                    <<<'SQL'
                        UPDATE refresh_tokens
                        SET revoked_at = COALESCE(revoked_at, NOW())
                        WHERE family_id = :family_id
                    SQL
                );

                $revokeFamily->execute(['family_id' => $current['family_id'],]);

                $this->pdo->commit();

                throw new RuntimeException('Refresh token reuse detected. Please log in again.');
            }

            if ($current['revoked_at'] !== null || !$this->databaseBoolean($current['is_unexpired'])) {
                throw new RuntimeException('Invalid refresh token.');
            }

            $replacementToken = $this->generateToken();

            $insert = $this->pdo->prepare(
                <<<'SQL'
                    INSERT INTO refresh_tokens (
                        user_id,
                        family_id,
                        token_hash,
                        expires_at
                    )
                    VALUES (
                        :user_id,
                        :family_id,
                        :token_hash,
                        NOW() + (:ttl * INTERVAL '1 second')
                    )
                    RETURNING id
                SQL
            );

            $insert->execute([
                'user_id' => $current['user_id'],
                'family_id' => $current['family_id'],
                'token_hash' => hash('sha256', $replacementToken),
                'ttl' => $this->refreshTokenTtl,
            ]);

            $replacementId = $insert->fetchColumn();

            if ($replacementId === false) {
                throw new RuntimeException(
                    'Could not create replacement refresh token.'
                );
            }

            $consume = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE refresh_tokens
                    SET consumed_at = NOW(),
                        replaced_by_id = :replacement_id
                    WHERE id = :id
                      AND consumed_at IS NULL
                      AND revoked_at IS NULL
                SQL
            );

            $consume->execute([
                'replacement_id' => $replacementId,
                'id' => $current['id'],
            ]);

            if ($consume->rowCount() !== 1) {
                throw new RuntimeException('Could not rotate refresh token.');
            }

            $accessToken = $this->jwtService->issueAccessToken(
                (string) $current['user_id']
            );

            $this->pdo->commit();

            return [
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => 900,
                'refresh_token' => $replacementToken,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Revoke the session associated with the supplied refresh token.
     */
    public function revoke(string $presentedToken): void
    {
        if ($presentedToken === '' || strlen($presentedToken) > 512) {
            return;
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE refresh_tokens
                SET revoked_at = COALESCE(revoked_at, NOW())
                WHERE family_id = (
                    SELECT family_id
                    FROM refresh_tokens
                    WHERE token_hash = :token_hash
                    LIMIT 1
                )
            SQL
        );

        $statement->execute(['token_hash' => hash('sha256', $presentedToken),]);
    }

    private function generateToken(): string
    {
        // 64 random bytes encoded as 128 hexadecimal characters.
        return bin2hex(random_bytes(64));
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true';
    }
}

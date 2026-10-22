<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Phalcon\Encryption\Security\JWT\Builder;
use Phalcon\Encryption\Security\JWT\Signer\Hmac;
use Phalcon\Encryption\Security\JWT\Token\Parser;
use Phalcon\Encryption\Security\JWT\Validator;
use RuntimeException;
use Throwable;

final class JwtService
{
    private Hmac $signer;
    private string $secret;
    private string $issuer;
    private string $audience;
    private int $ttl;

    public function __construct(
        string $secret,
        string $issuer,
        string $audience,
        int $ttl = 900
    ) {
        if (strlen($secret) < 32) {
            throw new RuntimeException(
                'JWT secret must contain at least 32 characters.'
            );
        }

        $this->signer = new Hmac('sha256');
        $this->secret = $secret;
        $this->issuer = $issuer;
        $this->audience = $audience;
        $this->ttl = $ttl;
    }

    public function issueAccessToken(string $userId): string
    {
        $now = time();

        $builder = new Builder($this->signer);

        $token = $builder
            ->setIssuer($this->issuer)
            ->setAudience($this->audience)
            ->setSubject($userId)
            ->setIssuedAt($now)
            ->setNotBefore($now)
            ->setExpirationTime($now + $this->ttl)
            ->setId(bin2hex(random_bytes(16)))
            ->setPassphrase($this->secret)
            ->getToken();

        return $token->getToken();
    }


    public function validate(string $jwt): object
    {
        try {
            $parser = new Parser();
            $token = $parser->parse($jwt);

            $validator = new Validator($token);

            $validator
                ->validateIssuer($this->issuer)
                ->validateAudience($this->audience)
                ->validateExpiration(time() + 1)
                ->validateIssuedAt(time())
                ->validateNotBefore(time())
                ->validateSignature($this->signer, $this->secret);

            $errors = $validator->getErrors();

            if ($errors !== []) {
                throw new RuntimeException(
                    'JWT validation errors: ' . var_export($errors, true)
                );
            }

            $claims = $token->getClaims();

            $subject = $claims->get('sub');

            if (!is_string($subject) || $subject === '') {
                throw new RuntimeException('JWT subject is missing.');
            }

            return (object) [
                'sub' => $subject,
                'iss' => $claims->get('iss'),
                'aud' => $claims->get('aud'),
                'jti' => $claims->get('jti'),
                'iat' => $claims->get('iat'),
                'exp' => $claims->get('exp'),
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'JWT validation failed.',
                0,
                $exception
            );
        }
    }

}

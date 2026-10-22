<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Phalcon\Http\Response;

final class JsonResponder
{
    public function success(mixed $data, int $status = 200, array $meta = []): Response
    {
        return $this->respond($status, [
            'success' => true,
            'data' => $data,
            'meta' => (object) $meta,
        ]);
    }

    public function error(string $code, string $message, int $status, array $details = []): Response
    {
        return $this->respond($status, [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ]);
    }

    private function respond(int $status, array $payload): Response
    {
        $response = new Response();
        $response->setStatusCode($status);
        $response->setContentType('application/json', 'UTF-8');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setJsonContent($payload);
        return $response;
    }
}

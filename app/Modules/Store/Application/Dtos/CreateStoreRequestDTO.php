<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Dtos;

use Phalcon\Http\RequestInterface;

final readonly class CreateStoreRequestDTO
{
    public function __construct(
        public mixed $name,
        public mixed $description,
    ) {
    }

    public static function fromRequest(RequestInterface $request): self {
        $data = $request->getJsonRawBody(true);

        if (!is_array($data)) {
            $data = $request->getPost();
        }

        $name = $data['name'] ?? null;
        $description = $data['description'] ?? null;
        
        return new self(
            name: is_string($name) ? trim($name) : $name,
            description: is_string($description)? trim($description): $description,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
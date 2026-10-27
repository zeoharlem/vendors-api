<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Dtos;

final readonly class CreateNotificationDto
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $userId,
        public string $type,
        public string $title,
        public string $message,
        public array $data = [],
    ) {
    }

    /**
     * @return array{
     *     user_id: string,
     *     type: string,
     *     title: string,
     *     message: string,
     *     data: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}

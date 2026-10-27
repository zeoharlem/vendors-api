<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Dtos;

final readonly class NotificationResponseDto
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public string $message,
        public array $data,
        public ?string $readAt,
        public ?string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $notification
     */
    public static function fromArray(array $notification): self
    {
        $data = $notification['data'] ?? [];

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id: (string) $notification['id'],
            type: (string) $notification['type'],
            title: (string) $notification['title'],
            message: (string) $notification['message'],
            data: is_array($data) ? $data : [],
            readAt: isset($notification['read_at'])
            ? (string) $notification['read_at']
            : null,
            createdAt: isset($notification['created_at'])
            ? (string) $notification['created_at']
            : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data,
            'read_at' => $this->readAt,
            'created_at' => $this->createdAt,
        ];
    }
}

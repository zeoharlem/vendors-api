<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Presentation\Validators;

final class RejectVendorValidator
{
    /**
     * @param array<string, mixed> $data
     * @return array{reason: string}
     */
    public function validate(array $data): array
    {
        $reason = $data['reason'] ?? null;

        if (!is_string($reason)) {
            throw new \InvalidArgumentException(
                'A rejection reason is required.'
            );
        }

        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < 10 || $length > 1000) {
            throw new \InvalidArgumentException(
                'Rejection reason must be between 10 and 1000 characters.'
            );
        }

        return ['reason' => $reason];
    }
}
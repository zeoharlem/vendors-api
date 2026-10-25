<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Presentation\Validators;

final class CreateVendorProfileValidator
{
    public static function validate(mixed $body): array
    {
        if (!is_array($body)) {
            return [
                'valid' => false,
                'errors' => [
                    'body' => 'A JSON object is required.',
                ],
            ];
        }

        $errors = [];

        if (
            !isset($body['business_name'])
            || !is_string($body['business_name'])
            || trim($body['business_name']) === ''
        ) {
            $errors['business_name'] = 'Business name is required.';
        } elseif (mb_strlen(trim($body['business_name'])) > 255) {
            $errors['business_name'] = 'Business name must not exceed 255 characters.';
        }

        if (
            array_key_exists('business_registration_number', $body)
            && $body['business_registration_number'] !== null
            && !is_string($body['business_registration_number'])
        ) {
            $errors['business_registration_number'] = 'Business registration number must be a string or null.';
        } elseif (
            is_string($body['business_registration_number'] ?? null)
            && mb_strlen(
                trim($body['business_registration_number'])
            ) > 255
        ) {
            $errors['business_registration_number'] = 'Business registration number must not exceed 255 characters.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
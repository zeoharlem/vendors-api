<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Validators;

use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\Callback;
use Phalcon\Filter\Validation\Validator\PresenceOf;

final class CreateStoreValidator extends Validation
{
    public function initialize(): void
    {
        $this->add(
            'name',
            new PresenceOf([
                'message' => 'The store name is required.',
            ]),
        );

        $this->add(
            'name',
            new Callback([
                'callback' => static fn(array $data): bool =>
                    is_string($data['name'] ?? null),
                'message' => 'The store name must be a string.',
            ]),
        );

        $this->add(
            'name',
            new Callback([
                'callback' => static function (array $data): bool {
                    $name = $data['name'] ?? null;

                    return !is_string($name)
                        || mb_strlen($name) >= 2;
                },
                'message' => 'The store name must contain at least 2 characters.',
            ]),
        );

        $this->add(
            'name',
            new Callback([
                'callback' => static function (array $data): bool {
                    $name = $data['name'] ?? null;

                    return !is_string($name)
                        || mb_strlen($name) <= 255;
                },
                'message' => 'The store name must not exceed 255 characters.',
            ]),
        );

        $this->add(
            'description',
            new Callback([
                'callback' => static fn(array $data): bool =>
                    ($data['description'] ?? null) === null
                    || is_string($data['description']),
                'message' => 'The description must be a string or null.',
            ]),
        );

        $this->add(
            'description',
            new Callback([
                'callback' => static function (array $data): bool {
                    $description = $data['description'] ?? null;

                    return !is_string($description)
                        || mb_strlen($description) <= 5000;
                },
                'message' => 'The description must not exceed 5000 characters.',
            ]),
        );
    }
}
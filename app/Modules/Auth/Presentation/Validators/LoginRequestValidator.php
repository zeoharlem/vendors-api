<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Validators;

use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\Email;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Filter\Validation\Validator\StringLength;

final class LoginRequestValidator extends Validation
{
    public function initialize(): void
    {
        $this->add(
            'email',
            new PresenceOf([
                'message' => 'Email is required.',
            ])
        );

        $this->add(
            'email',
            new Email([
                'message' => 'A valid email address is required.',
            ])
        );

        $this->add(
            'password',
            new PresenceOf([
                'message' => 'Password is required.',
            ])
        );

        $this->add(
            'password',
            new StringLength([
                'min' => 1,
                'messageMinimum' => 'Password is required.',
            ])
        );
    }
}

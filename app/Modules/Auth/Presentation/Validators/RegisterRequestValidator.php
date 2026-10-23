<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Validators;

use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\StringLength;
use Phalcon\Filter\Validation\Validator\Email;
use Phalcon\Filter\Validation\Validator\PresenceOf;

final class RegisterRequestValidator extends Validation
{
    public function initialize(): void
    {
        $this->add('email', new PresenceOf(
            [
                'message' => 'Email is required.',
            ]
        ));

        $this->add('email', new Email(
            [
                'message' => 'Please provide a valid email address.',
            ]
        ));

        $this->add('email', new StringLength(
            [
                'max' => 254,
                'messageMaximum' => 'Email cannot exceed 254 characters.',
            ]
        ));

        $this->add('first_name', new PresenceOf(
            [
                'message' => 'First name is required.',
            ]
        ));

        $this->add('first_name', new StringLength(
            [
                'max' => 100,
                'messageMaximum' => 'First name cannot exceed 100 characters.',
            ]
        ));

        $this->add('last_name', new PresenceOf(
            [
                'message' => 'Last name is required.',
            ]
        ));

        $this->add('last_name', new StringLength(
            [
                'max' => 100,
                'messageMaximum' => 'Last name cannot exceed 100 characters.',
            ]
        ));

        $this->add('password', new PresenceOf(
            [
                'message' => 'Password is required.',
            ]
        ));

        $this->add('password', new StringLength(
            [
                'min' => 12,
                'messageMinimum' => 'Password must be at least 12 characters.',
            ]
        ));

        $this->add('phone', new StringLength(
            [
                'max' => 30,
                'messageMaximum' => 'Phone cannot exceed 30 characters.',
                'allowEmpty' => true,
            ]
        ));
    }
}

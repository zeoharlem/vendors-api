<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Validators;

use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Filter\Validation\Validator\StringLength;

final class RespondToDisputeValidator extends Validation
{
    public function initialize(): void
    {
        $this->add(
            'message',
            new PresenceOf([
                'message' => 'Response message is required.',
            ])
        );

        $this->add(
            'message',
            new StringLength([
                'min' => 2,
                'max' => 5000,
                'messageMinimum' => 'Response message must contain at least 2 characters.',
                'messageMaximum' => 'Response message cannot exceed 5000 characters.',
            ])
        );
    }
}

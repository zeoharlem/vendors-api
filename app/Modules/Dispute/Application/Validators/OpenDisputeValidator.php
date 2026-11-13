<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Validators;

use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Filter\Validation\Validator\StringLength;

final class OpenDisputeValidator extends Validation
{
    public function initialize(): void
    {
        $this->add(
            'vendor_order_id',
            new PresenceOf([
                'message' => 'Vendor order ID is required.',
            ])
        );

        $this->add(
            'vendor_order_id',
            new StringLength([
                'max' => 36,
                'messageMaximum' => 'Vendor order ID is invalid.',
            ])
        );

        $this->add(
            'reason',
            new PresenceOf([
                'message' => 'Dispute reason is required.',
            ])
        );

        $this->add(
            'reason',
            new StringLength([
                'max' => 100,
                'messageMaximum' => 'Dispute reason cannot exceed 100 characters.',
            ])
        );

        $this->add(
            'description',
            new PresenceOf([
                'message' => 'Dispute description is required.',
            ])
        );

        $this->add(
            'description',
            new StringLength([
                'min' => 10,
                'max' => 5000,
                'messageMinimum' => 'Dispute description must contain at least 10 characters.',
                'messageMaximum' => 'Dispute description cannot exceed 5000 characters.',
            ])
        );
    }
}

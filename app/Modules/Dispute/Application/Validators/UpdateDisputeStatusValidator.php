<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Validators;

use App\Modules\Dispute\Domain\Enums\DisputeStatus;
use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\InclusionIn;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Filter\Validation\Validator\StringLength;

final class UpdateDisputeStatusValidator extends Validation
{
    public function initialize(): void
    {
        $this->add(
            'status',
            new PresenceOf([
                'message' => 'Dispute status is required.',
            ])
        );

        $this->add(
            'status',
            new InclusionIn([
                'domain' => [
                    DisputeStatus::UNDER_REVIEW->value,
                    DisputeStatus::AWAITING_CUSTOMER->value,
                    DisputeStatus::AWAITING_VENDOR->value,
                ],
                'message' => 'The requested dispute status is invalid.',
            ])
        );

        $this->add(
            'note',
            new StringLength([
                'max' => 2000,
                'messageMaximum' => 'Status note cannot exceed 2000 characters.',
            ])
        );
    }
}

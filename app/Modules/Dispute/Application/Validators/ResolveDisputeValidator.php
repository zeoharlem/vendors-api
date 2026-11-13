<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Validators;

use App\Modules\Dispute\Domain\Enums\DisputeResolutionOutcome;
use App\Modules\Dispute\Domain\Enums\DisputeStatus;
use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\InclusionIn;
use Phalcon\Filter\Validation\Validator\Numericality;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Filter\Validation\Validator\StringLength;

final class ResolveDisputeValidator extends Validation
{
    public function initialize(): void
    {
        $this->add(
            'outcome',
            new PresenceOf([
                'message' => 'Resolution outcome is required.',
            ])
        );

        $this->add(
            'outcome',
            new InclusionIn([
                'domain' => array_map(
                    static fn(DisputeResolutionOutcome $outcome): string => $outcome->value,
                    DisputeResolutionOutcome::cases()
                ),
                'message' => 'The selected resolution outcome is invalid.',
            ])
        );

        $this->add(
            'resolved_status',
            new PresenceOf([
                'message' => 'Resolution status is required.',
            ])
        );

        $this->add(
            'resolved_status',
            new InclusionIn([
                'domain' => [
                    DisputeStatus::RESOLVED_CUSTOMER->value,
                    DisputeStatus::RESOLVED_VENDOR->value,
                ],
                'message' => 'Resolution status must be resolved_customer or resolved_vendor.',
            ])
        );

        $this->add(
            'resolution',
            new PresenceOf([
                'message' => 'Resolution explanation is required.',
            ])
        );

        $this->add(
            'resolution',
            new StringLength([
                'min' => 10,
                'max' => 5000,
                'messageMinimum' => 'Resolution explanation must contain at least 10 characters.',
                'messageMaximum' => 'Resolution explanation cannot exceed 5000 characters.',
            ])
        );

        $this->add(
            'refund_amount_minor',
            new Numericality([
                'message' => 'Refund amount must be a valid number.',
            ])
        );
    }
}

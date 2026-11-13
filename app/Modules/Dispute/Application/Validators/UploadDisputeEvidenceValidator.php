<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Validators;

use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\PresenceOf;
use Phalcon\Filter\Validation\Validator\StringLength;

final class UploadDisputeEvidenceValidator extends Validation
{
    public function initialize(): void
    {
        $this->add(
            'dispute_id',
            new PresenceOf([
                'message' => 'Dispute ID is required.',
            ])
        );

        $this->add(
            'dispute_id',
            new StringLength([
                'max' => 36,
                'messageMaximum' => 'Dispute ID is invalid.',
            ])
        );
    }
}

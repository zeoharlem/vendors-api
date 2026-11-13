<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Enums;

enum DisputeEventType: string
{
    case DISPUTE_OPENED = 'dispute_opened';

    case STATUS_CHANGED = 'status_changed';

    case CUSTOMER_RESPONSE_SUBMITTED = 'customer_response_submitted';

    case VENDOR_RESPONSE_SUBMITTED = 'vendor_response_submitted';

    case EVIDENCE_UPLOADED = 'evidence_uploaded';

    case DISPUTE_RESOLVED = 'dispute_resolved';

    case DISPUTE_REJECTED = 'dispute_rejected';

    case DISPUTE_CLOSED = 'dispute_closed';

    case PAYABLE_HELD = 'payable_held';

    case PAYABLE_RELEASED = 'payable_released';

    case REFUND_REQUESTED = 'refund_requested';

    case REFUND_PROCESSED = 'refund_processed';

    case EVIDENCE_DELETED = 'evidence_deleted';
}

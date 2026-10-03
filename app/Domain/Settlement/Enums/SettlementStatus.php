<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Enums;

enum SettlementStatus: string
{
    case CREATED = 'created';
    case SUBMISSION_UNKNOWN = 'submission_unknown';
    case SUBMITTED = 'submitted';
    case CONFIRMED = 'confirmed';
    case FAILED = 'failed';
}

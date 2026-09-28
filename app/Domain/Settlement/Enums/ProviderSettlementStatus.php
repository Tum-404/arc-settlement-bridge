<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Enums;

enum ProviderSettlementStatus: string
{
    case PENDING = 'pending';
    case SUBMITTED = 'submitted';
    case CONFIRMED = 'confirmed';
    case FAILED = 'failed';
}

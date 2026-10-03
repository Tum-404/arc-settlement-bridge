<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Models;

use App\Domain\Settlement\Enums\SettlementStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $source
 * @property string $source_invoice_id
 * @property string $recipient
 * @property string $amount
 * @property string $currency
 * @property string $idempotency_key
 * @property string $provider_idempotency_key
 * @property SettlementStatus $status
 * @property string|null $provider
 * @property string|null $provider_transaction_id
 * @property string|null $tx_hash
 * @property string|null $failure_code
 * @property string|null $failure_reason
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $confirmed_at
 * @property CarbonInterface|null $failed_at
 * @property string $reconciliation_status
 * @property CarbonInterface|null $reconciled_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Collection<int, SettlementEvent> $events
 */
final class Settlement extends Model
{
    protected $table = 'settlements';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'amount' => 'string',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SettlementEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SettlementEvent::class, 'settlement_id')->orderBy('created_at', 'asc');
    }

    public function isCreated(): bool
    {
        return $this->status === SettlementStatus::CREATED;
    }

    public function isSubmitted(): bool
    {
        return $this->status === SettlementStatus::SUBMITTED;
    }

    public function isConfirmed(): bool
    {
        return $this->status === SettlementStatus::CONFIRMED;
    }

    public function isFailed(): bool
    {
        return $this->status === SettlementStatus::FAILED;
    }

    public function isSubmissionUnknown(): bool
    {
        return $this->status === SettlementStatus::SUBMISSION_UNKNOWN;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Models;

use App\Domain\Settlement\Enums\SettlementEventType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $settlement_id
 * @property SettlementEventType $type
 * @property array<string, mixed>|null $payload
 * @property CarbonInterface $created_at
 * @property-read Settlement $settlement
 */
final class SettlementEvent extends Model
{
    protected $table = 'settlement_events';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettlementEventType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Settlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'settlement_id');
    }
}

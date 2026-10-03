<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider
 * @property string $provider_event_id
 * @property array<string, mixed> $payload
 * @property CarbonInterface|null $processed_at
 * @property string $processing_status
 * @property int $attempts
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class ProviderWebhookEvent extends Model
{
    protected $table = 'provider_webhook_events';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}

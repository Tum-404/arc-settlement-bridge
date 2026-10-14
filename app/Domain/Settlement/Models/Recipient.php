<?php

declare(strict_types=1);

namespace App\Domain\Settlement\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $client_id
 * @property string|null $client_name
 * @property string $crypto_address
 * @property string $network
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|Recipient active()
 */
final class Recipient extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'client_name',
        'crypto_address',
        'network',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope a query to only include active recipients.
     *
     * @param  Builder<Recipient>  $query
     * @return Builder<Recipient>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Find active recipient by client reference and network.
     */
    public static function findActiveForClient(string $clientId, string $network = 'ARC-TESTNET'): ?self
    {
        return self::query()
            ->active()
            ->where(function (Builder $query) use ($clientId): void {
                $query->where('client_id', trim($clientId))
                    ->orWhere('client_name', trim($clientId));
            })
            ->where(function (Builder $query) use ($network): void {
                $query->where('network', strtoupper(trim($network)))
                    ->orWhere('network', strtolower(trim($network)));
            })
            ->first();
    }

    /**
     * Find recipient (active or inactive) by client reference and network.
     */
    public static function findForClient(string $clientId, string $network = 'ARC-TESTNET'): ?self
    {
        return self::query()
            ->where(function (Builder $query) use ($clientId): void {
                $query->where('client_id', trim($clientId))
                    ->orWhere('client_name', trim($clientId));
            })
            ->where(function (Builder $query) use ($network): void {
                $query->where('network', strtoupper(trim($network)))
                    ->orWhere('network', strtolower(trim($network)));
            })
            ->first();
    }
}

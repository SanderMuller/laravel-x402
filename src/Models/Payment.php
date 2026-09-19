<?php

declare(strict_types=1);

namespace X402\Laravel\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the publishable `x402_payments` table.
 *
 * Filament / Nova column hints:
 *
 * @property string             $id
 * @property string             $status        `settled` | `rejected`
 * @property string             $resource      Full URL the buyer hit (or formatted via `X402::resourceFormatter()`).
 * @property string|null        $payer         EVM address of the buyer.
 * @property string             $pay_to        EVM address of the recipient.
 * @property string             $amount        Atomic-units big-integer string (preserves precision).
 * @property string             $asset         Asset contract address.
 * @property string             $network       CAIP-2 network identifier.
 * @property string|null        $transaction   On-chain tx hash (settled rows only).
 * @property string|null        $nonce         EIP-3009 nonce (idempotency key).
 * @property string|null        $reason        Set on `rejected` rows.
 * @property array<string,mixed>|null $extensions
 * @property array<string,mixed>|null $meta
 * @property Carbon|null $settled_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
final class Payment extends Model
{
    use HasUlids;

    public const string STATUS_SETTLED = 'settled';

    public const string STATUS_REJECTED = 'rejected';

    protected $guarded = [];

    public function getTable(): string
    {
        $value = config('x402.history.table');

        return is_string($value) && $value !== '' ? $value : 'x402_payments';
    }

    public function getConnectionName(): ?string
    {
        $value = config('x402.history.connection');

        return is_string($value) && $value !== '' ? $value : parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extensions' => 'array',
            'meta' => 'array',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<self>
     */
    public static function settled(): Builder
    {
        return self::query()->where('status', self::STATUS_SETTLED);
    }

    /**
     * @return Builder<self>
     */
    public static function rejected(): Builder
    {
        return self::query()->where('status', self::STATUS_REJECTED);
    }

    /**
     * @return Builder<self>
     */
    public static function forResource(string $resource): Builder
    {
        return self::query()->where('resource', $resource);
    }

    /**
     * @return Builder<self>
     */
    public static function forPayer(string $address): Builder
    {
        return self::query()->where('payer', $address);
    }

    /**
     * @return Builder<self>
     */
    public static function between(DateTimeInterface $from, DateTimeInterface $to): Builder
    {
        return self::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);
    }
}

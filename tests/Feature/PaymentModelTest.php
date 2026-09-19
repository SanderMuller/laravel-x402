<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use X402\Laravel\Models\Payment;

beforeEach(function (): void {
    require_once __DIR__ . '/../../database/migrations/2026_01_01_000000_create_x402_payments_table.php';
    $migration = include __DIR__ . '/../../database/migrations/2026_01_01_000000_create_x402_payments_table.php';
    $migration->up();
});

afterEach(function (): void {
    $migration = include __DIR__ . '/../../database/migrations/2026_01_01_000000_create_x402_payments_table.php';
    $migration->down();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makePayment(array $overrides = []): Payment
{
    /** @var array<string, mixed> $attributes */
    $attributes = array_merge([
        'status' => Payment::STATUS_SETTLED,
        'resource' => 'https://example.test/premium',
        'payer' => '0xpayer',
        'pay_to' => '0xrecipient',
        'amount' => '10000',
        'asset' => '0xasset',
        'network' => 'eip155:8453',
        'transaction' => '0xtx' . bin2hex(random_bytes(8)),
        'nonce' => '0x' . bin2hex(random_bytes(32)),
        'extensions' => ['ext' => 'abc'],
        'meta' => ['tenant_id' => 't42'],
        'settled_at' => Date::now(),
    ], $overrides);

    /** @var Payment $created */
    $created = Payment::query()->create($attributes);

    return $created;
}

it('migration creates the x402_payments table on SQLite', function (): void {
    expect(Schema::hasTable('x402_payments'))->toBeTrue();
});

it('persists JSON-cast columns and ULID PK', function (): void {
    $payment = makePayment(['extensions' => ['receipt' => '0xabc'], 'meta' => ['user_id' => 7]]);

    /** @var Payment $reloaded */
    $reloaded = Payment::query()->findOrFail($payment->id);

    expect($reloaded->extensions)->toBe(['receipt' => '0xabc'])
        ->and($reloaded->meta)
        ->toBe(['user_id' => 7])
        ->and($reloaded->id)
        ->toHaveLength(26); // ULID length
});

it('settled and rejected scopes filter correctly', function (): void {
    makePayment();
    makePayment();
    makePayment(['status' => Payment::STATUS_REJECTED, 'reason' => 'denied', 'transaction' => null]);

    expect(Payment::settled()->count())->toBe(2)
        ->and(Payment::rejected()
            ->count())
        ->toBe(1);
});

it('forResource and forPayer scopes filter correctly', function (): void {
    makePayment(['resource' => 'https://example.test/a', 'payer' => '0xalice']);
    makePayment(['resource' => 'https://example.test/b', 'payer' => '0xbob']);

    expect(Payment::forResource('https://example.test/a')->count())->toBe(1)
        ->and(Payment::forPayer('0xalice')
            ->count())
        ->toBe(1);
});

it('between scope filters by created_at range', function (): void {
    Date::setTestNow('2026-01-01 12:00:00');
    makePayment();
    Date::setTestNow('2026-02-01 12:00:00');
    makePayment();
    Date::setTestNow('2026-03-01 12:00:00');
    makePayment();

    expect(Payment::between(
        Date::parse('2026-01-15'),
        Date::parse('2026-02-15'),
    )->count())->toBe(1);

    Date::setTestNow();
});

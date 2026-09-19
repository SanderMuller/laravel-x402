<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Facades\X402;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Tests\Stubs\StubFacilitator;
use X402\Replay\NonceStoreContract;
use X402\Server\PaymentResponseCache;

it('registers the x402.cache middleware alias', function (): void {
    $router = $this->app->make(Router::class);

    expect($router->getMiddleware())->toHaveKey('x402.cache');
});

it('binds PaymentResponseCache as a singleton', function (): void {
    $a = $this->app->make(PaymentResponseCache::class);
    $b = $this->app->make(PaymentResponseCache::class);

    expect($a)->toBeInstanceOf(PaymentResponseCache::class)
        ->and($b)->toBe($a);
});

it('replays the cached response when the same signed header arrives twice', function (): void {
    $fake = X402::fake();

    Route::middleware(['x402.cache', (string) RequirePayment::using('0.01')])
        ->get('/cached',
            // Different timestamp each call so a non-cached re-run would
            // produce a new body. If cache works, body stays identical.
            fn (): array => ['t' => microtime(true)]);

    $header = signedHeaderForCacheTest();

    $first = $this->withHeader('X-PAYMENT', $header)->get('/cached');
    expect($first->getStatusCode())->toBe(200);

    $second = $this->withHeader('X-PAYMENT', $header)->get('/cached');
    expect($second->getStatusCode())->toBe(200)
        ->and($second->getContent())
        ->toBe($first->getContent());

    // Settlement happened exactly once — second call short-circuited
    // inside PaymentResponseCache before the facilitator was reached.
    expect($fake->verifyCalls())->toHaveCount(1)
        ->and($fake->settleCalls())->toHaveCount(1);
});

it('forge guard — same (network, from, nonce) tuple but different signature bytes does not replay', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    Route::middleware(['x402.cache', (string) RequirePayment::using('0.01')])
        ->get('/forge', fn (): string => 'paid-content');

    $authorization = [
        'from' => '0xfrom',
        'to' => config('x402.recipient'),
        'value' => '10000',
        'validAfter' => Date::now()->getTimestamp() - 10,
        'validBefore' => Date::now()->getTimestamp() + 60,
        'nonce' => '0x' . bin2hex(random_bytes(32)),
    ];

    $genuine = base64_encode((string) json_encode([
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xgenuine' . bin2hex(random_bytes(16)),
            'authorization' => $authorization,
        ],
    ]));

    $forged = base64_encode((string) json_encode([
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            // Same (network, from, nonce) tuple as $genuine; different
            // signature bytes — this is the on-chain-observable replay
            // an attacker could mount without binding the cache key to
            // the raw header bytes.
            'signature' => '0xforged' . bin2hex(random_bytes(16)),
            'authorization' => $authorization,
        ],
    ]));

    $first = $this->withHeader('X-PAYMENT', $genuine)->get('/forge');
    expect($first->getStatusCode())->toBe(200);

    // The forged header has the same (network, from, nonce) public tuple
    // but different bytes. Cache must miss; PaymentEnforcer's nonce store
    // then rejects the duplicate authorization. Critically, the protected
    // body must NOT be returned — without this guard, an attacker who
    // observed the on-chain settlement could replay the cached "paid"
    // response without paying.
    $second = $this->withHeader('X-PAYMENT', $forged)->get('/forge');

    expect($second->getStatusCode())->not->toBe(200)
        ->and((string) $second->getContent())->not->toContain('paid-content')
        ->and((string) $second->getContent())->toContain('replay');
});

it('does not cache 402 responses', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator(verifyOk: false));

    Route::middleware(['x402.cache', (string) RequirePayment::using('0.01')])
        ->get('/rejected', fn (): string => 'should-never-render');

    $header = signedHeaderForCacheTest();

    $first = $this->withHeader('X-PAYMENT', $header)->get('/rejected');
    expect($first->getStatusCode())->toBe(402);

    // After verify failure, switch to a passing facilitator. If 402 had
    // been cached, the second call would still 402; instead the cache
    // misses and the new facilitator runs.
    $this->app->instance(FacilitatorClient::class, new StubFacilitator(verifyOk: true));

    $second = $this->withHeader('X-PAYMENT', signedHeaderForCacheTest())->get('/rejected');
    expect($second->getStatusCode())->toBe(200);
});

it('passes through unaffected when the route has no payment header', function (): void {
    Route::middleware('x402.cache')->get('/free', fn (): string => 'open');

    expect($this->get('/free')->getStatusCode())->toBe(200);
});

it('uses the configured cache_store when set', function (): void {
    config()->set('x402.response_cache.cache_store', 'array');

    // Re-resolve so the binding picks up the new config.
    $this->app->forgetInstance(PaymentResponseCache::class);
    $cache = $this->app->make(PaymentResponseCache::class);

    expect($cache)->toBeInstanceOf(PaymentResponseCache::class);
});

it('named-route cache scoping shares hits across query-string variants', function (): void {
    $fake = X402::fake();

    Route::middleware(['x402.cache', (string) RequirePayment::using('0.01')])
        ->get('/named', fn (): array => ['t' => microtime(true)])
        ->name('cache.named');

    $header = signedHeaderForCacheTest();

    // Same X-PAYMENT, same named route, different query strings — the
    // resourceResolver default keys off Route::current()->getName(), so
    // both retries collapse to the same cache scope.
    $first = $this->withHeader('X-PAYMENT', $header)->get('/named?ref=a');
    expect($first->getStatusCode())->toBe(200);

    $second = $this->withHeader('X-PAYMENT', $header)->get('/named?ref=b');
    expect($second->getStatusCode())->toBe(200)
        ->and($second->getContent())
        ->toBe($first->getContent())
        ->and($fake->settleCalls())
        ->toHaveCount(1);
});

it('unnamed routes fall back to the URI path for cache scoping', function (): void {
    $fake = X402::fake();

    Route::middleware(['x402.cache', (string) RequirePayment::using('0.01')])
        ->get('/unnamed', fn (): array => ['t' => microtime(true)]);

    $header = signedHeaderForCacheTest();

    $first = $this->withHeader('X-PAYMENT', $header)->get('/unnamed');
    $second = $this->withHeader('X-PAYMENT', $header)->get('/unnamed');

    expect($second->getContent())->toBe($first->getContent())
        ->and($fake->settleCalls())
        ->toHaveCount(1);
});

it('keeps the response-cache TTL independent of the nonce TTL', function (): void {
    // Sanity check: the binding pulls TTL from x402.response_cache.ttl,
    // so a config override is honoured rather than being silently shared
    // with the replay store's TTL.
    config()->set('x402.response_cache.ttl', 7200);

    $this->app->forgetInstance(PaymentResponseCache::class);
    $cache = $this->app->make(PaymentResponseCache::class);

    expect($cache)->toBeInstanceOf(PaymentResponseCache::class)
        ->and($this->app->make(NonceStoreContract::class))->not->toBe($cache);
});

function signedHeaderForCacheTest(): string
{
    return base64_encode((string) json_encode([
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xsignature' . bin2hex(random_bytes(8)),
            'authorization' => [
                'from' => '0xfrom',
                'to' => config('x402.recipient'),
                'value' => '10000',
                'validAfter' => Date::now()->getTimestamp() - 10,
                'validBefore' => Date::now()->getTimestamp() + 60,
                'nonce' => '0x' . bin2hex(random_bytes(32)),
            ],
        ],
    ]));
}

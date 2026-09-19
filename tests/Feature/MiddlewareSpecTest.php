<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Http\Middleware\MiddlewareSpec;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;
use X402\Laravel\Tests\Stubs\StubFacilitator;
use X402\Laravel\X402ServiceProvider;

it('serialises to legacy triple when no overrides are set', function (): void {
    $spec = RequirePayment::using('0.01');

    expect((string) $spec)->toBe(RequirePayment::class . ':0.01,USDC,base');
});

it('serialises to a v2 token when overrides are set', function (): void {
    $spec = RequirePayment::using('0.01')
        ->payTo('0xroute')
        ->describing('Premium API call');

    $string = (string) $spec;

    expect($string)->toStartWith(RequirePayment::class . ':' . MiddlewareSpec::TOKEN_PREFIX);

    $token = explode(':', $string, 2)[1];
    $decoded = MiddlewareSpec::decode($token, RequirePayment::class);

    expect($decoded->amount)->toBe('0.01')
        ->and($decoded->payTo)->toBe('0xroute')
        ->and($decoded->description)->toBe('Premium API call')
        ->and($decoded->botGated)->toBeFalse();
});

it('round-trips a closure-based skipWhen through the v2 token', function (): void {
    $spec = RequirePayment::using('0.01')->skipWhen(fn (): bool => true);

    $string = (string) $spec;
    $token = explode(':', $string, 2)[1];

    $decoded = MiddlewareSpec::decode($token, RequirePayment::class);

    expect($decoded->skipWhen)->toBeInstanceOf(Closure::class);

    $invoke = $decoded->skipWhen;
    assert($invoke instanceof Closure);
    expect($invoke(new Request()))->toBeTrue();
});

it('rejects a non-v2 token via decode()', function (): void {
    MiddlewareSpec::decode('x402-spec-legacy-hash', RequirePayment::class);
})->throws(RuntimeException::class, 'Not an x402 v2 spec token');

it('rejects a corrupt v2 payload', function (): void {
    MiddlewareSpec::decode(MiddlewareSpec::TOKEN_PREFIX . 'not-base64-and-not-serialized', RequirePayment::class);
})->throws(RuntimeException::class);

it('returns a fresh instance from each fluent setter', function (): void {
    $original = RequirePayment::using('0.01');

    expect($original->payTo('0xA'))->not->toBe($original)
        ->and($original->onNetwork('base-sepolia'))->not->toBe($original)
        ->and($original->asAsset('PYUSD'))->not->toBe($original)
        ->and($original->describing('Premium'))->not->toBe($original)
        ->and($original->skipWhen(fn (): bool => false))->not->toBe($original)
        ->and($original->payTo)
        ->toBeNull()
        ->and($original->description)
        ->toBeNull()
        ->and($original->skipWhen)
        ->toBeNull()
        ->and($original->network)
        ->toBe('base')
        ->and($original->asset)
        ->toBe('USDC');
});

it('rejects direct property writes', function (): void {
    $spec = RequirePayment::using('0.01');

    expect(new ReflectionClass($spec)->isReadOnly())->toBeTrue()
        ->and(function () use ($spec): void {
            new ReflectionProperty($spec, 'amount')->setValue($spec, '0.02');
        })
        ->toThrow(Error::class, 'readonly');
});

it('is bit-stable across two equivalent spec strings', function (): void {
    $a = (string) RequirePayment::using('0.01')->payTo('0xABC')->describing('X');
    $b = (string) RequirePayment::using('0.01')->payTo('0xABC')->describing('X');

    expect($a)->toBe($b);
});

it('immutability holds for RequirePaymentFromBots specs', function (): void {
    $spec = RequirePaymentFromBots::using('0.001');

    expect($spec->payTo('0xA'))->not->toBe($spec)
        ->and($spec->payTo)
        ->toBeNull()
        ->and((string) $spec)
        ->toStartWith(RequirePaymentFromBots::class . ':');
});

it('uses the per-route payTo override when challenging', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    Route::middleware((string) RequirePayment::using('0.01')->payTo('0xroute'))
        ->get('/per-route', fn () => 'ok');

    $response = $this->get('/per-route');

    expect($response->getStatusCode())->toBe(402);

    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toBeArray();
    /** @var array{accepts: list<array{payTo: string}>} $body */
    expect($body['accepts'][0]['payTo'])->toBe('0xroute');
});

it('skipWhen returns true bypasses enforcement for that route only', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    Route::middleware((string) RequirePayment::using('0.01')->skipWhen(fn (): bool => true))
        ->get('/skipped', fn () => 'free');

    expect($this->get('/skipped')->getStatusCode())->toBe(200);
});

it('survives a route:cache round-trip without the original spec ever re-running', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    // Build the middleware string in a "definition-time" scope, mirroring
    // routes/web.php evaluation. Reference is dropped before request time so
    // any in-process registry tracking would be wiped.
    $middlewareString = (string) RequirePayment::using('0.04')
        ->payTo('0xcacheable')
        ->describing('Cache-safe route');

    Route::middleware($middlewareString)->get('/cacheable', fn () => 'ok');

    // Force the router's route table to be re-resolved from the cached
    // string — same code path Laravel uses on a cached-route boot.
    /** @var Router $router */
    $router = $this->app->make(Router::class);
    $router->getRoutes()->refreshNameLookups();

    $response = $this->get('/cacheable');

    expect($response->getStatusCode())->toBe(402);

    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toBeArray();
    /** @var array{accepts: list<array{payTo: string, description?: string}>} $body */
    expect($body['accepts'][0]['payTo'])->toBe('0xcacheable')
        ->and($body['accepts'][0]['description'] ?? null)->toBe('Cache-safe route');
});

it('preserves a closure-based skipWhen across the route-cache simulation', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    $middlewareString = (string) RequirePayment::using('0.05')->skipWhen(fn (): bool => true);

    Route::middleware($middlewareString)->get('/cached-skip', fn () => 'free-after-cache');

    /** @var Router $router */
    $router = $this->app->make(Router::class);
    $router->getRoutes()->refreshNameLookups();

    expect($this->get('/cached-skip')->getStatusCode())->toBe(200);
});

it('onlyBots flips the botGated flag and forces v2 serialisation', function (): void {
    $spec = RequirePayment::using('0.01')->onlyBots();

    expect($spec->botGated)->toBeTrue()
        ->and((string) $spec)
        ->toStartWith(RequirePayment::class . ':' . MiddlewareSpec::TOKEN_PREFIX);
});

it('botGated specs short-circuit non-bots in RequirePayment', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    Route::middleware((string) RequirePayment::using('0.01')->onlyBots())
        ->get('/bot-only', fn () => 'free for humans');

    $human = $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh) Chrome/130.0')->get('/bot-only');
    $bot = $this->withHeader('User-Agent', 'GPTBot/1.0')->get('/bot-only');

    expect($human->getStatusCode())->toBe(200)
        ->and($bot->getStatusCode())
        ->toBe(402);
});

it('does not flush any per-worker spec state on the Octane RequestReceived listener', function (): void {
    /*
     * Regression for the active bug fixed in the post-0.5 code-quality
     * refactor spec: the Octane listener used to flush a per-worker spec
     * registry on RequestReceived, wiping boot-time route specs subsequent
     * requests still needed.
     *
     * v2 tokens removed the registry entirely, so the flush call no longer
     * exists and never can — but we keep the guard to surface any future
     * regression that re-introduces process-global per-worker spec state.
     *
     * Octane isn't installed in tests; assert via source inspection.
     */
    $file = new ReflectionClass(X402ServiceProvider::class)->getFileName();
    expect($file)->toBeString();

    $source = file_get_contents((string) $file);
    expect($source)->toBeString()
        ->not->toContain('MiddlewareSpecRegistry')
        ->not->toContain('SpecRegistry::flush()');
});

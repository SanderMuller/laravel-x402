# laravel-x402

HTTP 402 stablecoin payments for Laravel routes.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-x402.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-x402)
[![Tests](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-x402/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-x402/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/laravel-x402.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-x402)
[![License](https://img.shields.io/packagist/l/sandermuller/laravel-x402.svg?style=flat-square)](LICENSE)

```php
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;

// Free for humans, 0.001 USDC for AI agents.
Route::get('/articles/{article}', ArticleController::class)
    ->middleware(RequirePaymentFromBots::using('0.001'));
```

Implements the [x402 payment protocol](https://www.x402.org/) on top of
[`sandermuller/php-x402`](https://github.com/sandermuller/php-x402), the
framework-agnostic core. You can charge per HTTP request in USDC, vary the
price per resource, gate AI agents while leaving content free for humans,
or pay upstream APIs automatically via `Http::withX402()`.

> [!NOTE]
> **Status:** alpha. Public API may shift before `v1.0`.

## Requirements

- PHP 8.4+
- Laravel 12 or 13

## Install

```bash
composer require sandermuller/laravel-x402
php artisan x402:install
```

`x402:install` publishes the config and prompts for the recipient address
plus (optionally) a buyer wallet private key, appending them to `.env` if
not already set. The service provider is auto-discovered.

To run the steps manually:

```bash
php artisan vendor:publish --tag=x402-config
```

```dotenv
X402_RECIPIENT=0xYourReceivingAddress
X402_PRIVATE_KEY=0x...   # only needed for Http::withX402()
```

## Recipes

### Gate a route (flat price)

```php
use X402\Laravel\Http\Middleware\RequirePayment;

Route::get('/premium', PremiumController::class)
    ->middleware(RequirePayment::using('0.01'));        // 0.01 USDC on Base
```

`::using()` returns a chainable spec (Stringable). The string form
`->middleware('x402:0.01,USDC,base')` works too — the named middleware
alias is registered for both `x402` and `x402.bots`.

### Per-route overrides (fluent builder)

```php
Route::get('/premium', PremiumController::class)
    ->middleware(
        RequirePayment::using('0.01')
            ->payTo('0xRouteSpecificRecipient')
            ->onNetwork('polygon')
            ->describing('Premium API call')
            ->skipWhen(fn (Request $r) => $r->user()?->isPro() === true)
    );
```

Available overrides:

| Method | Effect |
|---|---|
| `->payTo($address)` | Override `x402.recipient` for this route. |
| `->onNetwork($slug)` | `base`, `base-sepolia`, `ethereum`, `polygon`, `arbitrum`, or raw CAIP-2. |
| `->asAsset($symbol)` | Display symbol in the challenge. |
| `->describing($text)` | Custom challenge description (otherwise auto-generated). |
| `->skipWhen($predicate)` | Per-route skip. Returning `true` bypasses enforcement for this route only. |

> [!NOTE]
> The spec is immutable; fluent setters return a new instance. Chain the
> calls or assign the result. Mutating an aliased reference does nothing.
>
> Specs survive `route:cache` since 0.8.0 — closures inside `->skipWhen()`
> ride along via `laravel/serializable-closure`, so cached routes
> reconstruct the spec from the middleware string alone.

### Variable price per resource

Implement `Priceable` on the bound model. The middleware reads the price
from the first `Priceable` route parameter; the static `::using()` amount
becomes the base price when no parameter is priceable.

```php
use X402\Laravel\Contracts\Priceable;

class Article extends Model implements Priceable
{
    public function x402Price(): string
    {
        return $this->premium ? '0.10' : '0.01';
    }
}

Route::get('/articles/{article}', ArticleController::class)
    ->middleware(RequirePayment::using('0.01'));        // base price
```

> [!IMPORTANT]
> `Priceable` resolution needs Laravel's `SubstituteBindings` middleware in
> the route's chain. Without it, route parameters stay as raw scalars and
> the price quietly falls back to the base amount. The `web` group includes
> it by default; on API-only routes add it explicitly. Laravel's middleware-
> priority list orders `SubstituteBindings` ahead of named middleware
> automatically, so declaration order doesn't matter as long as it's there.

When a route binds multiple `Priceable` parameters
(`/articles/{article}/extras/{extra}`), the first one in iteration order
wins. Override by reordering the route signature so the priceable to
charge is bound first, or by implementing `Priceable` only on the model
that should drive the price.

> [!NOTE]
> `Priceable` lookup keys off the URL **path** only — query strings are
> ignored. `/articles/42?utm=x` and `/articles/42` resolve to the same
> challenge. If you need pricing to vary by query parameter, compute it
> inside the bound model's `x402Price()` (it has access to the active
> `Request`) instead of relying on the resource resolver.

### Charge AI crawlers, free for humans

```php
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;

Route::get('/articles/{article}', ArticleController::class)
    ->middleware(RequirePaymentFromBots::using('0.001'));
```

User-Agent matched against a curated list (see
`X402\Server\BotDetector` from `sandermuller/php-x402`). Override or
extend in `config/x402.php`:

```php
'bots' => [
    // 'patterns' => ['ExactList', 'Of', 'Bots'],   // null = use defaults
    'extra_patterns' => ['MyResearchBot'],
],
```

Composes with `Priceable` (bots pay the model's price) and the builder
(`->skipWhen()`, `->payTo()`, etc. apply once the bot check passes).

### Skip enforcement globally (grace cache, IP allowlist, plan tier)

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use X402\Laravel\Facades\X402;

X402::enforceWhen(fn (Request $r) =>
    ! Cache::has("x402:paid:{$r->ip()}:{$r->path()}")
);
```

Returning `false` short-circuits the entire pipeline — no challenge, no
nonce claim, no facilitator round-trip.

> [!WARNING]
> Call `enforceWhen` once, from a service provider's `boot()`. The
> predicate is stored on a process-global singleton, so calling it from a
> controller, job, or middleware mutates enforcement for *all* subsequent
> requests under long-lived workers (Octane, RoadRunner). Put per-request
> logic *inside* the closure, which receives the current Request. The
> package restores the boot-time predicate on every Octane
> `RequestReceived` event when Octane is installed.

For per-route skipping, prefer `->skipWhen()` on the builder. It's scoped,
doesn't touch global state, and survives Octane.

### Read the settle receipt in your controller

After a successful settlement the `SettleResult` is exposed on the request:

```php
Route::get('/premium', function (Request $request) {
    $settle = $request->x402Settle();   // ?\X402\Facilitator\SettleResult

    return response()->json([
        'tx' => $settle?->transaction,
        'payer' => $settle?->payer,
    ]);
})->middleware(RequirePayment::using('0.01'));
```

Returns `null` when enforcement was skipped (e.g. `enforceWhen` returned
`false`, or the route is wrapped in `RequirePaymentFromBots` and the
caller was a human).

### Throttle unpaid 402 floods

The package registers a `throttle:x402` named rate limiter at 60 requests
per IP per minute by default. Apply it before the payment middleware so
unsigned requests don't tie up facilitator capacity:

```php
Route::get('/premium', PremiumController::class)
    ->middleware(['throttle:x402', RequirePayment::using('0.01')]);
```

Tune the cap via `X402_RATE_LIMIT_PER_MINUTE`. Set to `0` to skip the
package's registration entirely and define your own limiter under the
same key:

```php
RateLimiter::for('x402', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
```

### Pay outbound API calls

```php
$response = Http::withX402()->get('https://api.example.com/data');
```

The wallet key comes from `config/x402.php` (`X402_PRIVATE_KEY`). On any
upstream 402 the macro signs a fresh authorization, retries with
`X-PAYMENT`, and returns the second response.

For per-tenant or per-request wallet selection (HD wallet derivation,
KMS-backed signers, multi-tenant SaaS), bind a custom resolver and
optionally pass a context to the macro:

```php
use X402\Laravel\Client\WalletResolver;

$this->app->bind(WalletResolver::class, MyTenantWalletResolver::class);

Http::withX402(context: $tenantId)->post('https://api.example.com/...');
```

The `$context` is forwarded onto every `OutboundPaymentSent` event so
listeners can attribute spend back to a tenant, job, or correlation id
without parsing the URL.

### Wallet drivers

The buyer wallet used by `Http::withX402()` is selected by
`x402.wallet.driver`. Two drivers ship in 0.8.0:

| Driver        | Setup                                                                     | When to use                                                  |
|---------------|---------------------------------------------------------------------------|--------------------------------------------------------------|
| `private_key` | `X402_PRIVATE_KEY=0x…` (default — no `driver` env needed)                 | Local dev, single-key servers, low-stakes production         |
| `kms`         | `X402_WALLET_DRIVER=kms`, `X402_WALLET_KMS_PROVIDER=aws`, plus AWS config | SOC2 / FIPS environments where the key never touches the app |

The `kms` driver requires an extra Composer package — the package's
`composer.json` only `suggest`s the SDK so adopters who do not need it
do not pull a 200-package dependency tree:

```bash
composer require aws/aws-sdk-php
```

```php
// .env (AWS KMS)
X402_WALLET_DRIVER=kms
X402_WALLET_KMS_PROVIDER=aws
X402_WALLET_AWS_REGION=us-east-1
X402_WALLET_AWS_KEY_ID=arn:aws:kms:us-east-1:123:key/abc...
```

The KMS key MUST be `ECC_SECG_P256K1` with usage `SIGN_VERIFY`. Anything
else fails the first time `address()` runs.

`x402:verify-config` reports the active driver and the resolved address
so misconfiguration surfaces at boot, not at first paid call.

```text
$ php artisan x402:verify-config
Wallet driver: kms (aws)
Wallet address: 0xabc…
x402 config OK.
```

KMS adds 50–200ms per outbound payment. The Laravel HTTP client's
`pool()` parallelises across requests; per-request the latency is
unavoidable.

#### Per-tenant KMS

Tenants that each sign with a distinct KMS key bind
`TenantKmsWalletResolver` (a reference implementation; copy and adapt
when your tenant lookup differs):

```php
use Aws\Kms\KmsClient;
use X402\Laravel\Client\WalletResolver;
use X402\Laravel\Wallet\Resolvers\TenantKmsWalletResolver;

$this->app->bind(WalletResolver::class, fn ($app) => new TenantKmsWalletResolver(
    kms: $app->make(KmsClient::class),
    keyIdByTenant: [
        'acme'   => 'arn:aws:kms:us-east-1:123:key/acme...',
        'globex' => 'arn:aws:kms:us-east-1:123:key/globex...',
    ],
));
```

The default tenant extraction reads `Request::user()->tenant_id`. Pass
a `tenantIdResolver:` closure to the constructor when your dispatch
key lives elsewhere (job context, header, subdomain).

### Multi-tenant facilitator

Multi-tenant SaaS apps that ship a different facilitator per tenant —
typically per-tenant CDP credentials, occasionally per-tenant self-hosted
facilitator URLs — bind a custom `FacilitatorResolver`. The default
resolver returns the env-configured Coinbase facilitator on every
resolve; backward compatible with single-tenant setups.

```php
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use X402\Facilitator\CoinbaseFacilitator;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Facilitator\DispatchingFacilitatorFactory;
use X402\Laravel\Facilitator\FacilitatorResolver;
use X402\Laravel\Support\PaymentContextRegistry;

final readonly class TenantFacilitatorResolver implements FacilitatorResolver
{
    public function __construct(
        private FacilitatorClient $default,         // env-configured, already wrapped
        private TenantContext $tenants,
        private ClientInterface $http,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private Dispatcher $events,
        private PaymentContextRegistry $context,
        private Container $container,
    ) {}

    public function resolve(mixed $context = null): FacilitatorClient
    {
        $tenant = $this->tenants->current();

        if ($tenant?->facilitator_url === null) {
            return $this->default;
        }

        return DispatchingFacilitatorFactory::wrap(
            inner: new CoinbaseFacilitator(
                http: $this->http,
                requestFactory: $this->requestFactory,
                streamFactory: $this->streamFactory,
                baseUrl: $tenant->facilitator_url,
                defaultHeaders: ['Authorization' => 'Bearer ' . $tenant->facilitator_token],
            ),
            events: $this->events,
            context: $this->context,
            container: $this->container,
        );
    }
}
```

Register in a service provider:

```php
$this->app->bind(FacilitatorResolver::class, TenantFacilitatorResolver::class);
```

The resolver receives the current `Request` as `$context` so it can
dispatch on tenant id, headers, route, model — whatever the host
threads through.

**Caveats.**

- Custom resolvers MUST wrap each returned `FacilitatorClient` in
  `DispatchingFacilitator` (use `DispatchingFacilitatorFactory::wrap()`) —
  otherwise `PaymentSettled` / `PaymentRejected` events stop firing for
  that tenant. Pass `container:` so request-scoped context registered
  via `X402::capturePaymentContext()` rides along; without it, captured
  fields silently drop on tenant traffic.
- Resolvers should be singletons. Don't cache per-request
  `FacilitatorClient` instances on the resolver itself — that would
  bleed across requests in long-lived workers (Octane, RoadRunner).
- Facade calls (`X402::verify(...)`, `X402::settle(...)`) are NOT
  routed through the resolver. They always hit the default
  `FacilitatorClient` binding. Multi-tenant routing only applies to
  middleware-driven traffic; jobs and console commands operate on
  the configured default.

### Payment history

Opt-in (since 0.5.0). An Eloquent listener records every settled and
rejected payment to a publishable `x402_payments` table. Off by default;
enable in two steps:

```bash
php artisan vendor:publish --tag=x402-migrations
php artisan migrate
```

```php
// config/x402.php
'history' => [
    'enabled' => env('X402_HISTORY', true),
    'queue' => env('X402_HISTORY_QUEUE', null), // null = sync; set a queue name to defer
    'connection' => null,                        // separate analytics DB if you want one
    'table' => 'x402_payments',
],
```

```php
use X402\Laravel\Models\Payment;

Payment::settled()->where('payer', '0xalice')->latest()->take(10)->get();
Payment::rejected()->whereBetween('created_at', [$from, $to])->count();
```

Columns: `id` (ULID), `status`, `resource`, `payer`, `pay_to`,
`amount` (atomic units, big-int string), `asset`, `network`,
`transaction`, `nonce`, `reason`, `extensions` (json), `meta` (json),
`settled_at`, plus the standard timestamps. Writes are idempotent on
`transaction` and `nonce`: a retry through the `x402.cache` middleware
updates the existing row instead of inserting a duplicate.

#### Attach per-request context (`tenant_id`, `user_id`, `request_id`)

Register a closure once in a service provider's `boot()`. The closure
runs while the live `Request` is still in scope; the returned array
lands in the `meta` column on every history row, and rides queue
serialisation if you switch the listener to async.

```php
use Illuminate\Http\Request;
use X402\Laravel\Facades\X402;

X402::capturePaymentContext(fn (Request $r): array => [
    'user_id' => $r->user()?->id,
    'tenant_id' => $r->user()?->tenant_id,
    'request_id' => $r->headers->get('X-Request-Id'),
]);
```

#### Rewrite the `resource` for high-cardinality routes

Storing the full URL on `/articles/{id}` blows up cardinality fast.
Register a formatter to stash a route name (or any stable identifier)
instead:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use X402\Laravel\Facades\X402;

X402::resourceFormatter(function (string $url): string {
    try {
        return Route::getRoutes()->match(Request::create($url))->getName() ?? $url;
    } catch (NotFoundHttpException) {
        return $url;
    }
});
```

The formatter receives a URL string, not a live `Request`, so it
survives queue serialisation and the async-webhook dispatch path. The
`try/catch` is mandatory: `Route::getRoutes()->match()` throws when no
route matches (which happens on URLs that came in via the async-webhook
path), and an unhandled throw inside the formatter breaks event emission.

> [!NOTE]
> Hosts adding `belongsTo` relations (`User`, `Tenant`) extend the
> `X402\Laravel\Models\Payment` model in their own namespace. The
> shipped model is `final` for the read path; for relation work,
> compose via Eloquent global scopes or query the table directly.

#### Prune the `rejected` flood

Failed verifies on a public endpoint accumulate fast. The shipped
pruner is bounded — it deletes rows older than a window:

```bash
php artisan x402:prune --before=30days --status=rejected
php artisan x402:prune --before=2026-01-01 --status=settled
php artisan x402:prune --before=7days --dry-run
```

Schedule it from `app/Console/Kernel.php`:

```php
$schedule->command('x402:prune --before=30days --status=rejected')->daily();
```

### Idempotent paid responses (replay-on-retry)

If a client's connection drops between facilitator-settle and the
response landing, the same signed authorization can be retried — but
the nonce store will reject the duplicate and 402 a user who already
paid. Stack `x402.cache` *before* the payment middleware to short-
circuit the retry with the cached 2xx body:

```php
Route::middleware([
    'x402.cache',                    // cache lookup — short-circuits on hit
    RequirePayment::using('0.01'),   // payment enforcer (skipped on hit)
])->get('/premium', PremiumController::class);
```

Cache key: `(network, from, nonce, signature bytes, method, resource)`. A
forged signature with the same nonce will not replay, and a retry against
a different route (or method) cannot reuse another route's cached
response. TTL defaults to 1 hour; it has to exceed the replay window
(`X402_RESPONSE_CACHE_TTL`).

> [!NOTE]
> Cache scope is route-name-aware since 0.5.0. The service-provider
> binding passes a `resourceResolver:` closure that prefers
> `Route::current()?->getName()` over the raw URI path, so two retries
> against the same named route share cached responses across different
> query strings (which is normally what you want). Pricing-equivalent
> named routes share the same scope by design. If `articles.show` charges
> differently per query parameter, either rebind `PaymentResponseCache`
> without the resolver in your service provider, or split the routes so
> each pricing surface has its own name.

The cache stores the response status, body, and a safe-by-default header
allow-list (`Content-Type`, `Content-Length`, `Cache-Control`, `ETag`,
`Last-Modified`, `Location`, the `PAYMENT-RESPONSE` receipt, plus a few
others). `Set-Cookie`, `Authorization`, `Proxy-Authorization`,
`Www-Authenticate`, and `Cookie` are always dropped: anyone replaying a
stolen `X-PAYMENT` header would otherwise inherit the original buyer's
session. Hosts that need extra headers in the cached snapshot can extend
the allow-list:

```php
// config/x402.php
'response_cache' => [
    // ... existing keys ...
    'response_headers' => [
        // Defaults the upstream library ships:
        'Content-Type', 'Content-Language', 'Content-Length',
        'Content-Disposition', 'Cache-Control', 'ETag', 'Last-Modified',
        'Location', 'X-PAYMENT-RESPONSE', 'PAYMENT-RESPONSE',
        // App-specific addition:
        'Access-Control-Expose-Headers',
    ],
],
```

The hard-block list applies regardless of what you add.

What gets skipped: range, partial, and negotiated responses bypass the
cache and run fresh on each retry. Any of `Vary`, `Content-Range`,
`Content-Encoding`, `Accept-Ranges`, or a `206 Partial Content` status
triggers the skip. Don't stack `x402.cache` behind streaming responses
(`StreamedResponse` / `BinaryFileResponse`); the cache only helps when
the controller returns a fully-buffered body.

## Events

The middleware and outbound macro dispatch Laravel events so you can
record receipts, alert on failures, or hand work off to a queue.

| Event | Fires when | Payload |
|---|---|---|
| `X402\Laravel\Events\PaymentSettled` | Facilitator settles an inbound payment | `SettleResult $result`, `string $resource`, `?PaymentRequired $challenge`, `?PaymentSignature $signature`, `array $context` |
| `X402\Laravel\Events\PaymentRejected` | Facilitator rejects verify, or settle fails | `string $reason`, `string $resource`, `?PaymentRequired $challenge`, `?PaymentSignature $signature`, `array $context` |
| `X402\Laravel\Events\OutboundPaymentSent` | `Http::withX402()` countersigns a 402 challenge and retries | `string $url`, `string $amount`, `string $asset`, `string $network`, `string $payTo`, `mixed $context` |

```php
use X402\Laravel\Events\PaymentSettled;

Event::listen(PaymentSettled::class, function (PaymentSettled $e): void {
    Log::info('paid', [
        'resource' => $e->resource,
        'tx' => $e->result->transaction,
        'payer' => $e->result->payer,
    ]);
});
```

## Testing

```bash
composer test
```

The package ships a `FakeFacilitator` so consumers can test paid routes
without a network round-trip:

```php
use X402\Laravel\Events\PaymentSettled;
use X402\Laravel\Facades\X402;

it('charges and serves premium content', function (): void {
    Event::fake([PaymentSettled::class]);
    $fake = X402::fake();

    $this->withHeader('X-PAYMENT', $signedHeader)
        ->get('/premium')
        ->assertOk();

    $fake->assertSettled('https://localhost/premium');
    Event::assertDispatched(PaymentSettled::class);
});

// Drive failure paths:
X402::fake()->rejectVerify('insufficient-funds');
X402::fake()->failSettle('on-chain-revert');
```

`X402::fake()` swaps the bound `FacilitatorClient` for a recording fake
that still dispatches the same Laravel events — `Event::fake()` works
alongside. It also rebinds `FacilitatorResolver` to the fake, so a
tenant-aware resolver is bypassed for the duration of the test; to
exercise tenant routing, bind your own resolver explicitly with one
`FakeFacilitator` per tenant rather than calling `X402::fake()`.

## Console commands

| Command | Purpose |
|---|---|
| `php artisan x402:install` | Publish config and append `X402_RECIPIENT` / `X402_PRIVATE_KEY` to `.env`. Idempotent — existing values are never overwritten. |
| `php artisan x402:verify-config` | Validate config, resolve the wallet, report missing values. Pass `--ping` to additionally probe the configured facilitator URL with the configured auth headers. |
| `php artisan x402:list-routes` | Tabulate every route guarded by `RequirePayment` / `RequirePaymentFromBots` with its amount, network, asset, and per-route overrides. |
| `php artisan x402:test-payment {url}` | Send a test request through `Http::withX402()` and report the settlement. Flags: `--simulate-bot=GPTBot/1.0` to test the bots middleware, `--ping` for an unsigned request that just reports the 402 challenge, `--json` for machine-readable output. |
| `php artisan x402:prune` | Delete `x402_payments` rows older than the window. Flags: `--before=30days` (relative) or `--before=2026-01-01` (absolute), `--status=settled\|rejected`, `--dry-run` to preview. |

## Configuration

The most-set keys (see `config/x402.php` for the full reference):

| Key | Env | Default |
|-----|-----|---------|
| `recipient` | `X402_RECIPIENT` | _required_ |
| `network` | `X402_NETWORK` | `eip155:8453` (Base) — default for `x402:0.01,USDC` route-string macro when network is omitted |
| `networks` | _(array)_ | Slug → CAIP-2 map (`base`, `polygon`, …). Add custom chains here. |
| `asset.address` | `X402_ASSET_ADDRESS` | USDC on Base |
| `assets` | _(array)_ | Symbol → `{address, decimals, eip712}` map. Resolved when `RequirePayment::using('0.01', 'PYUSD')` picks a non-default asset. |
| `facilitator.url` | `X402_FACILITATOR_URL` | `https://x402.org/facilitator` |
| `wallet.driver` | `X402_WALLET_DRIVER` | `private_key` (also `kms`; see Wallet drivers recipe) |
| `wallet.private_key` | `X402_PRIVATE_KEY` | — (required when `driver=private_key`) |
| `wallet.kms.provider` | `X402_WALLET_KMS_PROVIDER` | — (`aws`; required when `driver=kms`) |
| `wallet.kms.aws.region` | `X402_WALLET_AWS_REGION` | — |
| `wallet.kms.aws.key_id` | `X402_WALLET_AWS_KEY_ID` | — |
| `replay.cache_store` | `X402_REPLAY_CACHE` | default cache store |
| `response_cache.cache_store` | `X402_RESPONSE_CACHE_STORE` | default cache store (used by the `x402.cache` middleware) |
| `response_cache.ttl` | `X402_RESPONSE_CACHE_TTL` | `3600` (seconds) |
| `response_cache.prefix` | _(string)_ | `x402:idem:v2:` (bumped from `v1` in 0.5.0 — adopters with custom prefixes should bump too; see [UPGRADING.md](UPGRADING.md)) |
| `bots.patterns` | _(array \| null)_ | `null` (use built-in list) |
| `bots.extra_patterns` | _(array)_ | `[]` |
| `history.enabled` | `X402_HISTORY` | `false` (opt-in payment-history persistence) |
| `history.queue` | `X402_HISTORY_QUEUE` | `null` (sync; set a queue name to defer writes) |
| `history.connection` | `X402_HISTORY_CONNECTION` | `null` (default DB connection) |
| `history.table` | `X402_HISTORY_TABLE` | `x402_payments` |

Custom networks and assets are picked up at request time — no rebuild
required. Supply your own:

```php
// config/x402.php
'networks' => [
    'base' => 'eip155:8453',
    'zora' => 'eip155:7777777',
],

'assets' => [
    'USDC' => [
        'address' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        'decimals' => 6,
        'eip712' => ['name' => 'USD Coin', 'version' => '2'],
    ],
    'PYUSD' => [
        'address' => '0x6c3ea9036406852006290770BEdFcAbA0e23A0e8',
        'decimals' => 6,
        'eip712' => ['name' => 'PayPal USD', 'version' => '1'],
    ],
],
```

## MCP support

For [`laravel/mcp`](https://github.com/laravel/mcp) integration, install
[`sandermuller/laravel-x402-mcp`](https://github.com/sandermuller/laravel-x402-mcp).

## Changelog

See [GitHub Releases](https://github.com/sandermuller/laravel-x402/releases)
for the version history, or [CHANGELOG.md](CHANGELOG.md).

## Upgrading

Breaking changes between minor versions live in
[UPGRADING.md](UPGRADING.md). `0.5.0` ships two source-level breaks
(`MiddlewareSpec` immutability and the `PaymentSettled` / `PaymentRejected`
constructor expansion) plus the `php-x402 ^0.4` cache-prefix migration.
Check the doc before bumping.

## Security

If you discover a security issue, follow the disclosure process in
[SECURITY.md](SECURITY.md).

## Credits

- [Sander Muller](https://github.com/SanderMuller)
- All [contributors](https://github.com/sandermuller/laravel-x402/graphs/contributors)

## License

MIT. See [LICENSE](LICENSE).

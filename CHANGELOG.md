# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.8.0 - 2026-09-19

<!-- verified-sha: 06dfb146068185616ea79fa42e9949cadd2e2216 -->
### Breaking changes

- **PHP 8.4 and Laravel 12 are now the floor.** `require.php` moved to `^8.4` and `illuminate/*` to `^12.0||^13.0`. Laravel 11 is dropped — the dev toolchain's `laravel/pao` conflicts with `laravel/framework <12.0`, and the suite now runs on Pest 5, which requires PHP `^8.4`. Staying on PHP 8.3 or Laravel 11 means pinning `^0.7.0`. Laravel 12 stays in `require`, but CI exercises Laravel 13 only: Pest 5 needs `symfony/process ^8.1` and Testbench 10 pins `^7.2`, so the pair cannot install together. Laravel 12 breakage is still treated as a bug.
- **Four public constants gained declared types** — `Payment::STATUS_SETTLED`, `Payment::STATUS_REJECTED`, `MiddlewareSpec::TOKEN_PREFIX` (`string`) and `BotDetector::DEFAULT_PATTERNS` (`array`). Reading them is unchanged; a subclass redeclaring one must use a compatible type. See [UPGRADING.md](UPGRADING.md).

### What's new

- **AWS KMS wallet driver.** Buyer wallet now selectable via `x402.wallet.driver`. The new `kms` value delegates to `X402\Client\AwsKmsWallet` so the signing key never touches the application server — required for SOC2 / FIPS environments. Configure with `X402_WALLET_DRIVER=kms`, `X402_WALLET_KMS_PROVIDER=aws`, `X402_WALLET_AWS_REGION`, `X402_WALLET_AWS_KEY_ID`. The KMS key MUST be `ECC_SECG_P256K1` with usage `SIGN_VERIFY`. `aws/aws-sdk-php` is in `suggest`, not `require` — adopters who do not need it pay no dependency cost; missing the SDK with `driver=kms` raises a clear `composer require aws/aws-sdk-php` hint at first resolve. Hosts that need a custom AWS client (named profile, custom retry, alternate endpoint) bind their own `KmsClient` in the container before this provider boots and the binding is honoured.
- **Per-tenant KMS reference (`TenantKmsWalletResolver`).** Mirror of the per-tenant `FacilitatorResolver` pattern from 0.6.0. Maps `tenant_id` → KMS key id; default extraction reads `Request::user()->tenant_id`, custom extraction via the `tenantIdResolver:` constructor closure. Copy and adapt rather than subclass.
- **`MiddlewareSpec` overrides survive `route:cache`.** Specs that set `payTo` / `describing` / `skipWhen` / `onlyBots` now serialise into the middleware string itself via `laravel/serializable-closure`, including closure-based `skipWhen` predicates. The previous registry-token mechanism (which silently dropped state on cached-route boots) is gone — the `Class:0.01,USDC,base` triple form is unchanged for adopters who never use overrides.
- **`x402:verify-config` reports the active wallet driver** alongside the resolved address, so misconfiguration surfaces at boot rather than at first paid call.

### Migration

- **No public-API change.** `RequirePayment::using()` and the fluent setters behave identically; existing routes that use only the legacy triple keep their wire format. `private_key` is the default driver — adopters who do not opt into `kms` need no env or config change.
- **`X402\Laravel\Http\Middleware\MiddlewareSpecRegistry` removed.** The class was marked `@internal` since 0.5.0; removal is the lift-out the v2 token mechanism made possible. Adopters who imported it directly (rare — its only caller was the spec itself and the route-list command, both updated) should drop the import.
- **php-x402 floor bumped to ^0.8.0.** The bump transitively closes a latent ECDSA bug present in php-x402 0.7.x and earlier: `SignatureExporter::toHex65` now enforces EIP-2 canonical low-s, so `PrivateKeyWallet` and `HdWallet` no longer emit "high-s" signatures that USDC `transferWithAuthorization` and OpenZeppelin `ECDSA.recover` reject. Recovery is identical (the address derived from the signature is unchanged), so any application logic that keys off the recovered address still works; only byte-pinned signature fixtures need regen. See upstream UPGRADING for the fixture migration note.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/compare/0.7.0...0.8.0

## 0.7.0 - 2026-05-10

### What's new

- **Bumped `php-x402` to `^0.7.0`.** Transitively unlocks the upstream async-settlement primitives — `SettleResult::pending()`, the `PaymentEnforcer` 202 path, the `X402\Webhook\*` namespace (`WebhookEvent`, `SignatureVerifier`, `WebhookDedupStore` interface), `PaymentRowBuilder::pendingRow()` — and `X402\Client\HdWallet` (BIP-32 with default derivation path `m/44'/60'/0'/0/N`, signs identically to `PrivateKeyWallet`). This release lets adopters consume those classes directly; Laravel-side wiring (route macro for the inbound webhook, a `Cache::add`-backed `WebhookDedupStore` implementation, an `hd` driver-dispatch arm in the wallet binding, `TenantHdWalletResolver` reference) lands in the next minor.
- **Octane `MiddlewareSpecRegistry` leak fixed.** The registry's `flush()` was being called on `Octane RequestReceived`, wiping boot-time route specs the next request needed — visible as 500s on the second request inside a long-lived worker. Fixed by treating boot-resolved specs as static; request-time mutation is now `@internal`-unsupported.
- **`MiddlewareSpec::onlyBots()`** fluent flag for parity with the `RequirePaymentFromBots` route macro. Use when you want the bot-only enforcement mode without the macro shorthand.
- **`x402.network` singular config now wires through to the route-string macros.** Previously a documented default that the `x402:0.01,USDC` shorthand silently ignored — adopters whose env relies on that fallback can now drop the per-route `onNetwork(...)` call.

### Internal

- Service-provider binding for `PaymentResponseCache` was rewritten to consume the new upstream `PaymentResponseCacheOptions` DTO (six optional knobs moved off the constructor). No behaviour change for adopters who let the package wire it.
- `RequirePayment` now resolves `AssetRegistry`, `NetworkRegistry`, and `BotDetector` via the container instead of inlining the config reads. Container-resolved everywhere in this package; if a downstream test hand-builds `new RequirePayment(...)`, add the three new dependencies (or build through `app(RequirePayment::class)`).
- New value objects extracted from previously inline code: `Support\AssetRegistry` + `AssetEntry`, `Support\NetworkRegistry`, `Detection\BotPatternConfig`, `Listeners\Support\PaymentIdentity`. All marked `@internal` for now.

### Migration

No public-API change in this adapter; no config-key change, no env-var change, no migration to run. Adopters who construct `X402\Server\PaymentResponseCache` themselves outside this package's service provider must adopt the new `PaymentResponseCacheOptions` DTO — see upstream's [UPGRADING.md `0.6.x → 0.7.0`](https://github.com/sandermuller/php-x402/blob/main/UPGRADING.md).

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/compare/0.6.0...0.7.0

## 0.6.0 - 2026-05-10

### What's new

- **Per-tenant `FacilitatorResolver`.** New contract mirrors the existing `WalletResolver` pattern so multi-tenant SaaS apps can ship a different facilitator per tenant — typically per-tenant CDP credentials, occasionally per-tenant self-hosted facilitator URLs. `RequirePayment` now resolves the facilitator per-request via the bound resolver instead of taking a `FacilitatorClient` directly. Default `ConfiguredFacilitatorResolver` returns the env-configured Coinbase facilitator on every resolve — single-tenant setups need no change.
- **`DispatchingFacilitatorFactory::wrap()` helper.** Custom resolvers wrap their per-tenant `FacilitatorClient` through this helper to keep the `PaymentSettled` / `PaymentRejected` event-firing invariant centralised. Pass `container:` so request-scoped context registered via `X402::capturePaymentContext()` rides along to async listeners.
- **`X402::fake()` rebinds both `FacilitatorClient` and `FacilitatorResolver`.** Tests against single-tenant code see no change. Tenant-aware test code should bind a custom resolver explicitly with one `FakeFacilitator` per tenant rather than calling `X402::fake()` — the facade docblock flags this and a reference test (`tests/Feature/RequirePaymentResolverIntegrationTest`) demonstrates the pattern.
- **README "Multi-tenant facilitator" recipe.** New section under Recipes covering resolver implementation, registration, and Octane caveats (don't cache per-request results on the resolver itself).

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/compare/0.5.0...0.6.0

## 0.5.0 - 2026-05-10

### What's new

- **`MiddlewareSpec` is immutable.** `final readonly class` with `with*()`-style copiers under the same names (`payTo`, `onNetwork`, `asAsset`, `describing`, `skipWhen`). Each call returns a fresh instance carrying the override; the original is untouched. Closes the aliasing footgun where `Route::middleware($spec)` could lazily stringify after a later `$spec->payTo(...)` mutation bled into an earlier route's registered token.
- **`MiddlewareSpecRegistry::register()` no longer clones on register.** The defensive `clone $spec` was a workaround for the mutable builder; with immutability it is redundant and was dropped. Token derivation (`sha256` over `middleware|amount|asset|network|payTo|description|spl_object_hash($skipWhen)`) is unchanged — equal specs continue to produce equal tokens within a single registry warm-up.
- **Payment history persistence — opt-in.** New publishable migration (`x402-migrations` tag) and `X402\Laravel\Models\Payment` Eloquent model record every settled and rejected payment to a `x402_payments` table. Off by default; flip `X402_HISTORY=true` and run `php artisan migrate` to enable. Idempotent on `transaction` + `nonce` so retries through the `x402.cache` middleware update the existing row instead of inserting a duplicate. Sync listener by default; flip `X402_HISTORY_QUEUE` to defer writes onto a queue. Includes `php artisan x402:prune --before=30days --status=rejected` to keep the rejected-flood manageable.
- **`X402::capturePaymentContext()` and `X402::resourceFormatter()`.** Two new facade methods. The capture closure attaches host-supplied per-request context (`tenant_id`, `user_id`, `request_id`) to every payment event, captured while the live `Request` is in scope so queued listeners receive it in payload memory. The formatter rewrites the `resource` field — typically a route name or stable identifier — before dispatch, so high-cardinality URLs (`/articles/{id}`) collapse to a single bucket in the history table.
- **`PaymentSettled` / `PaymentRejected` constructors gained `?PaymentRequired $challenge`, `?PaymentSignature $signature`, and `array $context`.** All three are nullable / defaulted, but listeners that destructure event constructors positionally need to update — see UPGRADING.md for the diff.

### Migration

- **Chained calls keep working bit-for-bit.** The README pattern `RequirePayment::using('0.01')->payTo('0x...')->describing('Premium')` is consumed by Laravel's middleware list — no caller change required.
- **Discarded return values are now silent no-ops.** Audit for:
  ```php
  $spec = RequirePayment::using('0.01');
  $spec->payTo($address);                      // <-- value discarded; spec unchanged
  Route::get('/x', X)->middleware($spec);
  
  
  
  
  ```
  Fix by chaining or assigning: `$spec = $spec->payTo($address);`.
- **Direct property writes now raise `Error: Cannot modify readonly property`.** Search call sites for assignments to `MiddlewareSpec::$amount|asset|network|payTo|description|skipWhen` — none exist in this package, but downstream code that reached past the public API will need to be rewritten to use the fluent setters.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/compare/0.4.0...0.5.0

## 0.4.0 - 2026-05-09

### What's new

- **`x402.cache` snapshots are now privacy-safe by default.** Upstream `php-x402` 0.3.0's `PaymentResponseCache` filters cached response headers through `DEFAULT_RESPONSE_HEADER_ALLOWLIST` (`Content-Type`, `Content-Language`, `Content-Length`, `Content-Disposition`, `Cache-Control`, `ETag`, `Last-Modified`, `Location`, plus the v1/v2 `PAYMENT-RESPONSE` receipt). `Set-Cookie`, `Authorization`, `Proxy-Authorization`, `Www-Authenticate`, and `Cookie` are hard-blocked regardless of allow-list configuration — anyone replaying a stolen `X-PAYMENT` header would otherwise inherit the original buyer's session. Filtering is applied on both write and read paths, so any pre-existing cached snapshots from `laravel-x402` 0.3.0 (php-x402 0.2.x) are sanitised on first hit after upgrade — no operator action required.
- **`x402.response_cache.response_headers` config knob** — extend the upstream allow-list with app-specific headers (CORS exposure, custom telemetry headers). Defaults to `null` (use upstream's list as-is). The hard-block list is enforced upstream regardless and cannot be opted out of.
- **`X402\Laravel\Support\SchemeMap`** — readonly wrapper around `array<string, SchemeContract>`. Single source of truth for the scheme map shared by `RequirePayment` (driving `PaymentEnforcer`) and the service-provider binding for `PaymentResponseCache`. Hosts that register a custom scheme rebind `SchemeMap` once and both halves of the middleware stack pick it up — closes the operator-drift failure mode upstream's 0.3.0 audit flagged.
- **Variant-response responses now skip the idempotent cache** instead of caching incorrectly. Range / negotiated / partial-content responses (`206 Partial Content`, or any response carrying `Vary` / `Content-Range` / `Content-Encoding` / `Accept-Ranges`) flow through the enforcer fresh on each retry. Streaming routes (`StreamedResponse` / `BinaryFileResponse`) should still not be stacked behind `x402.cache`; the cache only helps when the controller returns a fully-buffered body.

### Notes

- **Requires `sandermuller/php-x402` `^0.3`.** Upstream's `PaymentResponseCache` constructor changed (now requires `schemes:` — the same map `PaymentEnforcer` takes); the service-provider binding was updated accordingly. Adopters that bind their own `PaymentResponseCache` instance must supply `schemes:`.
- Adopters with cached snapshots from `laravel-x402` 0.3.0 need no action — upstream's read-path hard-block sanitises pre-existing entries on first hit. If your TTL is shorter than 1 hour, snapshots will be naturally evicted before the upgrade window closes.
- Custom schemes: pre-1.0 the public `SchemeMap` shape is a single map property. Adopters who wire a custom `SchemeContract` rebind `X402\Laravel\Support\SchemeMap::class` in their service provider and both `RequirePayment` and `PaymentResponseCache` pick up the change.
- README's "Idempotent paid responses — replay-on-retry" recipe gained three paragraphs: what's stored (allow-list + receipt header), how to extend the allow-list, what's skipped (variant + range responses + streaming routes).
- `x402.bots`, `x402` named middlewares unchanged.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/compare/0.3.0...0.4.0

## 0.3.0 - 2026-05-09

Bug-fix and hardening release. Adds an idempotent paid-response cache (`x402.cache` middleware) so a dropped client connection between facilitator-settle and response-delivery no longer 402s the user who already paid. `OutboundPaymentSent` gains a `mixed $context` field for tenant / job correlation. Several quality fixes addressed by an internal audit. Tests pass on the CI matrix.

### What's new

- **`x402.cache` middleware** — Laravel adapter for upstream `X402\Server\PaymentResponseCache`. Stack it *before* `RequirePayment` in a route's middleware list and the cached 2xx body is replayed when the same signed authorization is retried after a transport error. Cache key is `(network, from, nonce, signature bytes)`, so a forged signature with the same nonce does not replay. PSR-16 backed via the new `LaravelPsr16Bridge` over Illuminate's cache; per-route opt-in. Configured under `x402.response_cache` (`cache_store`, `ttl`, `prefix`).
- **`OutboundPaymentSent::$context`** — `Http::withX402($privateKey, $context)` now threads `$context` (mixed: tenant id, job id, correlation hash) onto the dispatched event. Listeners attribute outbound spend back to its origin without parsing URLs. Defaulted to `null` — a non-breaking addition.
- **`MiddlewareSpecRegistry::has()`** — companion to `resolve()` for callers that want "spec or absent" semantics without a try/catch (`x402:list-routes` uses it to stay tolerant of stale tokens).

### Bug fixes

- **`MiddlewareSpec` no longer mutates the cached entry under its own token.** Fluent setters return `$this`, and the registry hashed the live object — a builder kept alive after `(string) $spec` could rewrite the resolved spec from a controller. Registry now snapshots via `clone` on register; mutating the original after stringify has no effect on what the request sees.
- **`MiddlewareSpecRegistry` no longer leaks across Octane requests.** `static $specs` accumulated forever in long-lived workers. The Octane `RequestReceived` listener (the one that already restores `EnforcementPolicy`) now calls `MiddlewareSpecRegistry::flush()` so each request rebuilds its own spec set from `routes/web.php`.
- **`DispatchingFacilitator` no longer swallows transport exceptions.** A thrown `verify()` / `settle()` (network / HTTP error) bypassed `PaymentRejected`, silently undercounting facilitator failures. Both methods now emit `PaymentRejected` with the exception class + message as reason and rethrow.
- **`RequirePayment` now rejects unknown asset symbols** instead of silently falling back to the default. Previously `RequirePayment::using('0.01', 'USD')` (typo for `'USDC'`) picked the default asset on a multi-asset host — security-relevant. Throws with a list of known symbols; the configured default symbol still falls back as before.
- **`InstallCommand` quotes `.env` values** containing whitespace, `#`, `"`, `'`, `\`, or `$`. Without this a recipient or key with a `#` was truncated to a comment marker on the next `Dotenv` parse.
- **Global `X402::enforceWhen()` predicate now short-circuits before PSR / price-table / enforcer construction**, so a bypassed request pays no per-request allocation cost. `enforceWhen()` itself throws on a missing application instance (matches `fake()`) rather than silently no-op'ing.
- **`X402::enforceWhen()`, `X402::fake()` consistency** — both throw a `RuntimeException` when the facade application is unbound. Adopters debugging a non-firing predicate get a signal instead of silence.

### Notes

- Pre-1.0 minor: public API may still shift before `v1.0`. `OutboundPaymentSent` gained a positional argument; downstream listeners using named-argument construction are unaffected, positional users should add `null` for `$context`.
- `MiddlewareSpecRegistry::resolve()` now throws on unknown tokens instead of returning `?MiddlewareSpec`. The thrown message carries the same "did you cache routes?" hint that `RequirePayment::handle()` previously inlined. Use the new `has()` helper if you need the nullable semantics.
- `FakeFacilitator::$verifyCalls` and `$settleCalls` are now private — read them via `verifyCalls()` / `settleCalls()` getters. Public assertion helpers (`assertVerified`, `assertSettled`, `assertNothingSettled`) are unchanged.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/compare/0.2.0...0.3.0

## 0.2.0 - 2026-05-08

Tracks `sandermuller/php-x402` `^0.2`. Adds a `MiddlewareSpec` registry so `::using()` and the string alias `x402:…` resolve through the same path, a `FakeFacilitator` testing seam reachable via `X402::fake()`, an `OutboundPaymentSent` event, and two new console commands (`x402:install`, `x402:list-routes`). Tests pass on the CI matrix.

### What's new

- **`MiddlewareSpec` + `MiddlewareSpecRegistry`** — `RequirePayment::using()` and `RequirePaymentFromBots::using()` now return a `Stringable` spec backed by a per-request registry. The chainable overrides (`->payTo()`, `->onNetwork()`, `->describing()`, `->skipWhen()`, …) attach to the spec, and the named middleware alias resolves the same spec for the string form (`->middleware('x402:0.01,USDC,base')`). Object and string registrations now agree on overrides instead of diverging.
- **`X402::fake()`** — facade method swaps the bound `FacilitatorClient` for a `X402\Laravel\Testing\FakeFacilitator` that records calls and lets tests script outcomes (`assertSettled('https://localhost/premium')`, `rejectVerify('insufficient-funds')`, `failSettle('on-chain-revert')`). Laravel `Event::fake()` works alongside — the same `PaymentSettled` / `PaymentRejected` events still dispatch.
- **`DispatchingFacilitator`** — internal decorator that fires the existing inbound events plus a new `X402\Laravel\Events\OutboundPaymentSent` whenever `Http::withX402()` countersigns a 402 challenge and retries. Listeners can record outbound spend, alert on failures, or hand work off to a queue.
- **`x402:install` console command** — publishes `config/x402.php` and appends `X402_RECIPIENT` (and optionally `X402_PRIVATE_KEY`) to `.env`. Idempotent; existing values are never overwritten.
- **`x402:list-routes` console command** — tabulates every route guarded by `RequirePayment` / `RequirePaymentFromBots` with its amount, network, asset, and per-route overrides. Useful for auditing pricing across a large route file.
- **`x402:verify-config --ping`** — extends the existing config validator with a live probe of the configured facilitator URL using the configured auth headers.
- **`WalletResolver` / `ConfiguredWalletResolver`** — the buyer-wallet lookup is now a swappable contract instead of an inline closure. Adopters with KMS / HD-wallet flows can bind their own resolver without subclassing the macro.

### Notes

- **Requires `sandermuller/php-x402` `^0.2`.** No public-API breaks in `laravel-x402` itself — the upstream `PaymentEnforcer::default()` → `forTesting()` rename lives behind the service provider, and the new `ResourceResolver` / `EnforcementPolicy` core interfaces are wired automatically.
- Pre-1.0 minor: public API may still shift before `v1.0`. The `MiddlewareSpec` shape is stabilising but not frozen.
- `package-boost` dev-dep bumped to `^0.14`. `boost:update` artisan alias is gone — scripts should call `vendor/bin/testbench package-boost:sync` instead.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/compare/0.1.0...0.2.0

## [0.1.0] - 2026-05-08

First release. Laravel adapter for the [x402 payment protocol](https://www.x402.org/) — gate routes behind HTTP 402 stablecoin payments per request, vary the price per resource, charge AI agents while keeping content free for humans, or pay outbound API calls automatically. Built on top of [`sandermuller/php-x402`](https://github.com/sandermuller/php-x402) `^0.1`.

### What's new

- **`RequirePayment` middleware** — gate any route with `->middleware(RequirePayment::using('0.01'))` (or the string form `'x402:0.01,USDC,base'`). Static `::using()` mirrors Laravel 11's `Authenticate::using('api')` for typed, refactor-safe entry points.
- **Variable per-resource pricing** — implement `X402\Laravel\Contracts\Priceable` on a route-bound model and the middleware reads the price from `x402Price()` instead of the static amount. Built on the framework-agnostic `PriceTable` seam: `EloquentPriceTable` scans `$request->route()->parameters()` for the first `Priceable`, falls back to the middleware's static amount otherwise.
- **`RequirePaymentFromBots` middleware** — User-Agent matched against a curated list (sourced from knownagents.com). Humans pass through free; bots get the standard challenge → settle → 200 flow. Composes transparently with `Priceable` and `EnforcementPolicy`. Detector binding is transient + cached by config content so per-tenant overrides work under Octane / RoadRunner.
- **`X402::enforceWhen($predicate)` facade** — global `(Request): bool` hook that wraps the core's `shouldEnforce` slot. Returning `false` short-circuits the entire pipeline (no challenge, no nonce claim, no facilitator round-trip). Designed for grace caches, IP allowlists, plan-tier bypass, and geo policy.
- **`Http::withX402()` macro** — outbound auto-pay. Signs a fresh authorization for each request the upstream returns 402 on, retries with `X-PAYMENT`. Buyer wallet resolved from `X402_PRIVATE_KEY`.
- **Console commands** — `x402:verify-config` and `x402:test-payment` for setup verification.
- **MCP companion** — for `laravel/mcp` integration, install [`sandermuller/laravel-x402-mcp`](https://github.com/sandermuller/laravel-x402-mcp) (separate package).

### Notes

- **Requires PHP 8.2+ and Laravel 11 or 12.**
- `Priceable` resolution requires `Illuminate\Routing\Middleware\SubstituteBindings` to be in the route chain (true by default in the `web` group).
- `X402::enforceWhen()` should be called once, from a service provider's `boot()` — the predicate is stored on a process-global singleton, so per-request logic belongs *inside* the closure, not in repeated calls.
- Public API is alpha; signatures may shift before `v1.0`.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/commits/0.1.0

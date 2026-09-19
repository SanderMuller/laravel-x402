<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Native;
use Laravel\SerializableClosure\Serializers\Signed;
use Laravel\SerializableClosure\Signers\Hmac;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Immutable fluent builder for `RequirePayment` / `RequirePaymentFromBots`.
 * Returned by `::using()`, serialised to a Laravel middleware string on cast.
 *
 * Two wire forms:
 *
 * - **Legacy triple** (no overrides) → `Class:0.01,USDC,base`. Zero overhead,
 *   stable for `route:cache`.
 * - **`v2` payload** (any of `payTo`, `description`, `skipWhen`, `botGated`
 *   set) → `Class:x402-spec-v2-<base64url>`. The token IS the spec —
 *   `decode()` reconstructs the instance, no registry warm-up required.
 *   Cache-safe under `route:cache`. `skipWhen` closures ride along via
 *   `laravel/serializable-closure`.
 *
 * Each fluent setter returns a NEW instance — chain the calls or assign the
 * result; mutating an aliased reference is not supported.
 *
 * Laravel's router casts middleware list entries to strings, so a Spec is
 * accepted anywhere a middleware string is — no `(string)` needed at the
 * call site.
 */
final readonly class MiddlewareSpec implements Stringable
{
    /**
     * @param  class-string  $middleware  Concrete middleware class this spec resolves to.
     * @param  ?Closure(Request): bool  $skipWhen
     */
    public function __construct(
        public string $middleware,
        public string $amount,
        public string $asset = 'USDC',
        public string $network = 'base',
        public ?string $payTo = null,
        public ?string $description = null,
        public ?Closure $skipWhen = null,
        public bool $botGated = false,
    ) {}

    public function payTo(string $address): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $this->asset,
            network: $this->network,
            payTo: $address,
            description: $this->description,
            skipWhen: $this->skipWhen,
            botGated: $this->botGated,
        );
    }

    public function onNetwork(string $slug): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $this->asset,
            network: $slug,
            payTo: $this->payTo,
            description: $this->description,
            skipWhen: $this->skipWhen,
            botGated: $this->botGated,
        );
    }

    public function asAsset(string $symbol): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $symbol,
            network: $this->network,
            payTo: $this->payTo,
            description: $this->description,
            skipWhen: $this->skipWhen,
            botGated: $this->botGated,
        );
    }

    public function describing(string $description): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $this->asset,
            network: $this->network,
            payTo: $this->payTo,
            description: $description,
            skipWhen: $this->skipWhen,
            botGated: $this->botGated,
        );
    }

    /**
     * Per-route skip predicate. Returning true skips enforcement for THIS
     * route only — distinct from the global `X402::enforceWhen()` predicate.
     *
     * @param  Closure(Request): bool  $predicate
     */
    public function skipWhen(Closure $predicate): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $this->asset,
            network: $this->network,
            payTo: $this->payTo,
            description: $this->description,
            skipWhen: $predicate,
            botGated: $this->botGated,
        );
    }

    /**
     * Charge only requests detected as bots / AI agents (User-Agent based).
     * Equivalent to routing through `RequirePaymentFromBots` but composes
     * with the rest of the fluent builder (`payTo`, `describing`, etc.).
     */
    public function onlyBots(): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $this->asset,
            network: $this->network,
            payTo: $this->payTo,
            description: $this->description,
            skipWhen: $this->skipWhen,
            botGated: true,
        );
    }

    public const string TOKEN_PREFIX = 'x402-spec-v2-';

    public function __toString(): string
    {
        if ($this->payTo === null && $this->description === null && ! $this->skipWhen instanceof Closure && ! $this->botGated) {
            return $this->middleware . ':' . $this->amount . ',' . $this->asset . ',' . $this->network;
        }

        return $this->middleware . ':' . self::TOKEN_PREFIX . $this->base64UrlEncode(serialize([
            'amount' => $this->amount,
            'asset' => $this->asset,
            'network' => $this->network,
            'payTo' => $this->payTo,
            'description' => $this->description,
            'skipWhen' => $this->skipWhen instanceof Closure ? new SerializableClosure($this->skipWhen) : null,
            'botGated' => $this->botGated,
        ]));
    }

    /**
     * Reconstruct a spec from a `x402-spec-v2-…` token. The middleware
     * class is supplied by the caller (the router prefix already encodes
     * which middleware is dispatching).
     *
     * @param  class-string  $middleware
     */
    public static function decode(string $token, string $middleware): self
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX)) {
            throw new RuntimeException(sprintf('Not an x402 v2 spec token: "%s".', $token));
        }

        $payload = substr($token, strlen(self::TOKEN_PREFIX));

        try {
            $decoded = unserialize(self::base64UrlDecode($payload), [
                'allowed_classes' => [SerializableClosure::class, ...self::serializableClosureInternalClasses()],
            ]);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Failed to decode x402 spec token; payload is corrupt or signed with a different key.', 0, $throwable);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Decoded x402 spec token is not an array.');
        }

        $skipWhen = $decoded['skipWhen'] ?? null;
        if ($skipWhen instanceof SerializableClosure) {
            $skipWhen = $skipWhen->getClosure();
        }

        return new self(
            middleware: $middleware,
            amount: isset($decoded['amount']) && is_string($decoded['amount']) ? $decoded['amount'] : '0',
            asset: isset($decoded['asset']) && is_string($decoded['asset']) ? $decoded['asset'] : 'USDC',
            network: isset($decoded['network']) && is_string($decoded['network']) ? $decoded['network'] : 'base',
            payTo: isset($decoded['payTo']) && is_string($decoded['payTo']) ? $decoded['payTo'] : null,
            description: isset($decoded['description']) && is_string($decoded['description']) ? $decoded['description'] : null,
            skipWhen: $skipWhen instanceof Closure ? $skipWhen : null,
            botGated: isset($decoded['botGated']) && $decoded['botGated'] === true,
        );
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $payload): string
    {
        $padded = $payload . str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('x402 spec token payload is not valid base64url.');
        }

        return $decoded;
    }

    /**
     * SerializableClosure ships several internal classes (Native, Signed,
     * ClosureStream, Hmac, …) that are restored during unserialize. Walking
     * the package's `Serializers` and `Signers` namespaces at boot would be
     * fragile across upstream versions; the explicit allow-list here trades
     * a touch of staleness for a hard cap on what unserialize() may construct.
     *
     * @return list<class-string>
     */
    private static function serializableClosureInternalClasses(): array
    {
        return [
            Native::class,
            Signed::class,
            Hmac::class,
        ];
    }
}

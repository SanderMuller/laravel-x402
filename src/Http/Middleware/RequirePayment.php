<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use X402\Laravel\Facilitator\FacilitatorResolver;
use X402\Laravel\Server\EloquentPriceTable;
use X402\Laravel\Support\AssetRegistry;
use X402\Laravel\Support\ConfigReader;
use X402\Laravel\Support\EnforcementPolicy;
use X402\Laravel\Support\NetworkRegistry;
use X402\Laravel\Support\SchemeMap;
use X402\Protocol\PaymentRequired;
use X402\Protocol\Version;
use X402\Replay\NonceStoreContract;
use X402\Server\BotDetector;
use X402\Server\PaymentEnforcer;
use X402\Support\PriceParser;

/**
 * Laravel route middleware adapter — wraps the framework-agnostic
 * PaymentEnforcer from php-x402.
 *
 * Usage (string syntax):
 *
 *   Route::get('/premium', PremiumController::class)
 *       ->middleware('x402:0.01,USDC,base');
 *
 * Usage (fluent syntax — preferred):
 *
 *   Route::get('/premium', PremiumController::class)
 *       ->middleware(RequirePayment::using('0.01'));
 *
 * Middleware parameters:
 *   - amount       (decimal in human units, e.g. 0.01 USDC = "10000" atomic)
 *   - asset symbol (USDC by default — informational, asset address is from config)
 *   - network slug (base | base-sepolia | ethereum | polygon | arbitrum, or raw CAIP-2)
 *
 * Per-request behaviour:
 *   - Bound route parameters implementing {@see Priceable} override the static
 *     amount (first match wins).
 *   - The {@see EnforcementPolicy} predicate (if registered via
 *     {@see X402::enforceWhen()}) can short-circuit the
 *     entire pipeline before any challenge / nonce / facilitator work.
 */
final readonly class RequirePayment
{
    public function __construct(
        private FacilitatorResolver $facilitators,
        private NonceStoreContract $nonceStore,
        private ConfigRepository $config,
        private Psr17Factory $psr17,
        private PsrHttpFactory $symfonyToPsr,
        private HttpFoundationFactory $psrToSymfony,
        private EnforcementPolicy $policy,
        private LoggerInterface $logger,
        private SchemeMap $schemes,
        private AssetRegistry $assets,
        private NetworkRegistry $networks,
        private BotDetector $botDetector,
    ) {}

    /**
     * Type-safe entry point for `Route::middleware(...)` — mirrors Laravel's
     * `Authenticate::using('api')` convention. Returns a chainable spec
     * (Stringable), so:
     *
     *   ->middleware(RequirePayment::using('0.01'))
     *   ->middleware(RequirePayment::using('0.01')->payTo('0x...')->describing('Premium'))
     *
     * Both work — the spec serializes to the colon-separated middleware
     * string at the moment Laravel hashes its middleware list.
     *
     * **`$network` default is the literal `'base'`, not `x402.network` config.**
     * The `x402.network` operator default is wired only into the route-string
     * macro (`Route::middleware('x402:0.01,USDC')` with no network arg). The
     * fluent builder always carries an explicit slug so the spec wire-format
     * stays bit-stable across processes — pass `network:` explicitly if you
     * want a non-`base` chain.
     */
    public static function using(string $amount, string $asset = 'USDC', string $network = 'base'): MiddlewareSpec
    {
        return new MiddlewareSpec(
            middleware: self::class,
            amount: $amount,
            asset: $asset,
            network: $network,
        );
    }

    public function handle(Request $request, Closure $next, string $amount = '0', string $asset = 'USDC', string $networkSlug = ''): Response
    {
        $spec = null;

        if ($networkSlug === '') {
            // Route-string macro `x402:0.01,USDC` (no network arg) defers
            // to the operator-configured `x402.network`. The fluent
            // `RequirePayment::using()` builder still defaults to 'base'
            // explicitly so the spec wire-format stays bit-stable.
            $networkSlug = ConfigReader::string($this->config, 'x402.network', 'eip155:8453');
        }

        if (str_starts_with($amount, MiddlewareSpec::TOKEN_PREFIX)) {
            $spec = MiddlewareSpec::decode($amount, self::class);

            $amount = $spec->amount;
            $asset = $spec->asset;
            $networkSlug = $spec->network;

            if ($spec->skipWhen instanceof Closure && ($spec->skipWhen)($request)) {
                /** @var Response $response */
                $response = $next($request);

                return $response;
            }

            if ($spec->botGated && ! $this->botDetector->isBot((string) $request->userAgent())) {
                /** @var Response $response */
                $response = $next($request);

                return $response;
            }
        }

        // Honour the global `X402::enforceWhen()` predicate before any PSR
        // bridge / price-table / enforcer construction so a bypassed request
        // pays no per-request allocation cost.
        $globalPredicate = $this->policy->predicate();
        if ($globalPredicate instanceof Closure && ! $globalPredicate($request)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        // PSR-7's UriInterface::getPath() always returns a leading slash; Laravel's
        // Request::path() strips it. Normalise here so the price-table lookup
        // matches the resourceResolver's PSR-derived key.
        $resourcePath = '/' . ltrim($request->path(), '/');

        /** @var array<string, mixed> $routeParameters */
        $routeParameters = $request->route()?->parameters() ?? [];

        $priceTable = new EloquentPriceTable(
            routeParameters: $routeParameters,
            expectedResource: $resourcePath,
            challengeBuilder: fn (string $resolvedAmount): PaymentRequired => $this->buildChallenge($resolvedAmount, $asset, $networkSlug, $request, $spec),
            fallbackAmount: $amount,
        );

        $facilitator = $this->facilitators->resolve($request);

        $enforcer = new PaymentEnforcer(
            priceTable: $priceTable,
            facilitator: $facilitator,
            nonceStore: $this->nonceStore,
            schemes: $this->schemes->map,
            responseFactory: $this->psr17,
            streamFactory: $this->psr17,
            version: Version::from(ConfigReader::string($this->config, 'x402.version', 'v1')),
            resourceResolver: static fn (ServerRequestInterface $psr) => $psr->getUri()->getPath(),
            // Global EnforcementPolicy is checked above (see $globalPredicate); the
            // core shouldEnforce slot stays at its null default.
            logger: $this->logger,
        );

        $psrRequest = $this->symfonyToPsr->createRequest($request);

        $handler = new PsrInnerHandler($next, $request, $this->symfonyToPsr);

        $psrResponse = $enforcer->process($psrRequest, $handler);

        return $this->psrToSymfony->createResponse($psrResponse);
    }

    private function buildChallenge(string $amount, string $assetSymbol, string $networkSlug, Request $request, ?MiddlewareSpec $spec = null): PaymentRequired
    {
        $entry = $this->assets->get($assetSymbol);
        $atomic = PriceParser::toAtomic($amount, $entry->decimals);

        $payTo = $spec instanceof MiddlewareSpec && $spec->payTo !== null
            ? $spec->payTo
            : ConfigReader::string($this->config, 'x402.recipient');
        if ($payTo === '') {
            throw new RuntimeException('x402.recipient is not configured. Set X402_RECIPIENT in your environment.');
        }

        return new PaymentRequired(
            scheme: 'exact',
            network: $this->networks->resolve($networkSlug),
            amount: $atomic,
            asset: $entry->address,
            payTo: $payTo,
            maxTimeoutSeconds: ConfigReader::int($this->config, 'x402.max_timeout_seconds', 60),
            resource: $request->fullUrl(),
            description: $spec instanceof MiddlewareSpec && $spec->description !== null
                ? $spec->description
                : $assetSymbol . ' payment for ' . $request->path(),
            extra: [
                'name' => $entry->eip712Name,
                'version' => $entry->eip712Version,
            ],
        );
    }
}

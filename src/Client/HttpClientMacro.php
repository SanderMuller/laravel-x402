<?php

declare(strict_types=1);

namespace X402\Laravel\Client;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Date;
use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use X402\Client\PrivateKeyWallet;
use X402\Client\Wallet;
use X402\Exceptions\X402Exception;
use X402\Laravel\Events\OutboundPaymentSent;
use X402\Laravel\Support\ConfigReader;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Schemes\Evm\AuthorizationSigner;
use X402\Schemes\Evm\Eip712Hasher;
use X402\Support\JsonReader;

/**
 * Registers the `Http::withX402()` macro on Laravel's HTTP client factory.
 *
 * Implementation: a Guzzle handler-stack middleware that catches 402, signs
 * an EIP-3009 authorization with the operator wallet, and retries with the
 * PAYMENT-SIGNATURE header. Mirrors php-x402's PayingClient but plugs into
 * Guzzle's promise pipeline directly so it composes with retry, logging,
 * and macro chains the host already uses.
 */
final class HttpClientMacro
{
    public static function register(HttpFactory $http, Container $container): void
    {
        $factory = static function (?string $privateKey = null, mixed $context = null) use ($container): callable {
            $wallet = $privateKey !== null
                ? new PrivateKeyWallet($privateKey)
                : $container->make(WalletResolver::class)->resolve($context);

            $version = Version::from(ConfigReader::string($container->make(Repository::class), 'x402.version', 'v1'));
            $events = $container->make(Dispatcher::class);

            return self::guzzleMiddleware($wallet, $version, $events, $context);
        };

        $http::macro('withX402', function (?string $privateKey = null, mixed $context = null) use ($factory): PendingRequest {
            /** @var PendingRequest $self */
            $self = $this; // @phpstan-ignore-line — macro $this binding

            return $self->withMiddleware($factory($privateKey, $context));
        });
    }

    /**
     * Build the Guzzle handler-stack middleware closure.
     */
    public static function guzzleMiddleware(Wallet $wallet, Version $version, Dispatcher $events, mixed $context = null): Closure
    {
        return static fn (callable $next): Closure => static function (RequestInterface $request, array $options) use ($next, $wallet, $version, $events, $context) {
            /** @var PromiseInterface $promise */
            $promise = $next($request, $options);

            return $promise->then(static function (ResponseInterface $response) use ($next, $request, $options, $wallet, $version, $events, $context) {
                if ($response->getStatusCode() !== 402) {
                    return $response;
                }

                // Version negotiation: server's wire trumps configured version.
                $v2Header = Version::V2->challengeHeader();
                $negotiated = $v2Header !== null && $response->hasHeader($v2Header)
                    ? Version::V2
                    : $version;

                $challenge = self::decodeChallenge($response, $negotiated);
                $signed = self::sign($wallet, $challenge, $negotiated);

                $paid = $request->withHeader($negotiated->signatureHeader(), $signed->toHeader());

                $events->dispatch(new OutboundPaymentSent(
                    url: (string) $request->getUri(),
                    amount: $challenge->amount,
                    asset: $challenge->asset,
                    network: $challenge->network,
                    payTo: $challenge->payTo,
                    context: $context,
                ));

                return $next($paid, $options);
            });
        };
    }

    private static function decodeChallenge(ResponseInterface $response, Version $version): PaymentRequired
    {
        // v2 puts the challenge in PAYMENT-REQUIRED header; v1 in the body.
        $challengeHeader = $version->challengeHeader();
        $headerLine = $challengeHeader !== null ? $response->getHeaderLine($challengeHeader) : '';

        if ($headerLine !== '') {
            $decodedB64 = base64_decode($headerLine, true);
            $body = $decodedB64 === false ? '' : $decodedB64;
        } else {
            $body = (string) $response->getBody();
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new X402Exception('Could not decode 402 challenge: ' . $jsonException->getMessage(), $jsonException->getCode(), previous: $jsonException);
        }

        $accepts = $decoded['accepts'] ?? [];
        if (! is_array($accepts) || $accepts === []) {
            throw new X402Exception('402 response contained no challenges.');
        }

        $firstRaw = $accepts[0] ?? null;
        if (! is_array($firstRaw)) {
            throw new X402Exception('402 challenge entry is not an object.');
        }

        /** @var array<string, mixed> $first */
        $first = $firstRaw;

        $amount = JsonReader::stringOrNull($first, 'amount')
            ?? JsonReader::string($first, 'maxAmountRequired', '402 challenge');

        return new PaymentRequired(
            scheme: JsonReader::string($first, 'scheme', '402 challenge'),
            network: JsonReader::string($first, 'network', '402 challenge'),
            amount: $amount,
            asset: JsonReader::string($first, 'asset', '402 challenge'),
            payTo: JsonReader::string($first, 'payTo', '402 challenge'),
            maxTimeoutSeconds: JsonReader::int($first, 'maxTimeoutSeconds', default: 60),
            extra: JsonReader::arrayOrEmpty($first, 'extra'),
        );
    }

    private static function sign(Wallet $wallet, PaymentRequired $challenge, Version $version): PaymentSignature
    {
        $now = Date::now()
            ->getTimestamp();
        $message = [
            'from' => $wallet->address(),
            'to' => $challenge->payTo,
            'value' => $challenge->amount,
            'validAfter' => $now - 5,
            'validBefore' => $now + $challenge->maxTimeoutSeconds,
            'nonce' => AuthorizationSigner::randomNonce(),
        ];

        $chainId = str_starts_with($challenge->network, 'eip155:')
            ? (int) substr($challenge->network, 7)
            : 0;

        $extraName = $challenge->extra['name'] ?? '';
        $extraVersion = $challenge->extra['version'] ?? '2';

        $domain = [
            'name' => is_string($extraName) ? $extraName : '',
            'version' => is_string($extraVersion) ? $extraVersion : '2',
            'chainId' => $chainId,
            'verifyingContract' => $challenge->asset,
        ];

        $digest = new Eip712Hasher()->digest($domain, $message);
        $signature = $wallet->signDigest($digest);

        return new PaymentSignature(
            scheme: $challenge->scheme,
            network: $challenge->network,
            payload: ['signature' => $signature, 'authorization' => $message],
            x402Version: $version->toInt(),
        );
    }
}

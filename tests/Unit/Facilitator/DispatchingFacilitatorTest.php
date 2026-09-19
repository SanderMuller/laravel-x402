<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;
use X402\Laravel\Facilitator\DispatchingFacilitator;
use X402\Laravel\Support\PaymentContextRegistry;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

function dispatchingChallenge(): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0xrecipient',
        resource: 'https://localhost/test',
    );
}

function dispatchingSignature(): PaymentSignature
{
    return new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'signature' => '0xdeadbeef',
            'authorization' => [
                'from' => '0xfrom',
                'to' => '0xrecipient',
                'value' => '10000',
                'validAfter' => '0',
                'validBefore' => '99999999999',
                'nonce' => '0x' . str_repeat('a', 64),
            ],
        ],
    );
}

it('emits PaymentRejected and rethrows when inner verify() throws', function (): void {
    $events = new Dispatcher();
    $captured = [];
    $events->listen(PaymentRejected::class, function (PaymentRejected $e) use (&$captured): void {
        $captured[] = $e;
    });

    $inner = new class implements FacilitatorClient {
        public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
        {
            throw new RuntimeException('connection refused');
        }

        public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
        {
            return new SettleResult(success: true, transaction: '', network: '', payer: '');
        }

        public function supported(): SupportedKinds
        {
            return new SupportedKinds(kinds: []);
        }

        public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
        {
            return new DiscoveryPage(items: [], limit: 0, offset: 0, total: 0);
        }
    };

    $facilitator = new DispatchingFacilitator($inner, $events, new PaymentContextRegistry());

    expect(fn () => $facilitator->verify(dispatchingSignature(), dispatchingChallenge()))
        ->toThrow(RuntimeException::class, 'connection refused')
        ->and($captured)
        ->toHaveCount(1)
        ->and($captured[0]->reason)
        ->toContain('verify-error: RuntimeException: connection refused');
});

it('emits PaymentRejected and rethrows when inner settle() throws', function (): void {
    $events = new Dispatcher();
    $captured = [];
    $settledFired = false;
    $events->listen(PaymentRejected::class, function (PaymentRejected $e) use (&$captured): void {
        $captured[] = $e;
    });
    $events->listen(PaymentSettled::class, function () use (&$settledFired): void {
        $settledFired = true;
    });

    $inner = new class implements FacilitatorClient {
        public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
        {
            return new VerifyResult(isValid: true, payer: '0xpayer');
        }

        public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
        {
            throw new RuntimeException('rpc timeout');
        }

        public function supported(): SupportedKinds
        {
            return new SupportedKinds(kinds: []);
        }

        public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
        {
            return new DiscoveryPage(items: [], limit: 0, offset: 0, total: 0);
        }
    };

    $facilitator = new DispatchingFacilitator($inner, $events, new PaymentContextRegistry());

    expect(fn () => $facilitator->settle(dispatchingSignature(), dispatchingChallenge()))
        ->toThrow(RuntimeException::class, 'rpc timeout')
        ->and($captured)
        ->toHaveCount(1)
        ->and($captured[0]->reason)
        ->toContain('settle-error: RuntimeException: rpc timeout')
        ->and($settledFired)
        ->toBeFalse();
});

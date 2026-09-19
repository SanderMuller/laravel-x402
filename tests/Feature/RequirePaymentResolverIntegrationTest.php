<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;
use X402\Laravel\Facades\X402;
use X402\Laravel\Facilitator\FacilitatorResolver;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Testing\FakeFacilitator;

beforeEach(function (): void {
    Route::middleware((string) RequirePayment::using('0.01'))->get('/premium', fn () => 'paid content');
});

function resolverIntegrationHeader(): string
{
    return base64_encode((string) json_encode([
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xdeadbeef',
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

it('RequirePayment uses the bound FacilitatorResolver, not FacilitatorClient directly', function (): void {
    $captured = [];

    $tenantFake = new FakeFacilitator();
    $tenantWrapped = wrapForFacilitatorTest($tenantFake);

    $defaultFake = new FakeFacilitator();
    $defaultWrapped = wrapForFacilitatorTest($defaultFake);

    $resolver = new class ($tenantWrapped, $defaultWrapped, $captured) implements FacilitatorResolver {
        /**
         * @param  array<int, mixed>  $captured
         */
        public function __construct(
            private readonly FacilitatorClient $tenant,
            private readonly FacilitatorClient $default,
            public array &$captured,
        ) {}

        public function resolve(mixed $context = null): FacilitatorClient
        {
            $this->captured[] = $context;

            return $context instanceof Request && $context->headers->get('X-Tenant') === 'a'
                ? $this->tenant
                : $this->default;
        }
    };

    $this->app->instance(FacilitatorResolver::class, $resolver);

    $this->withHeaders(['X-Tenant' => 'a', 'X-PAYMENT' => resolverIntegrationHeader()])
        ->get('/premium')
        ->assertOk();

    $this->withHeaders(['X-Tenant' => 'b', 'X-PAYMENT' => resolverIntegrationHeader()])
        ->get('/premium')
        ->assertOk();

    expect($tenantFake->settleCalls())->toHaveCount(1)
        ->and($defaultFake->settleCalls())
        ->toHaveCount(1)
        ->and($resolver->captured)
        ->toHaveCount(2)
        ->and($resolver->captured[0])
        ->toBeInstanceOf(Request::class);
});

it('default resolver still dispatches PaymentSettled events', function (): void {
    Event::fake([PaymentSettled::class]);

    X402::fake();

    $this->withHeader('X-PAYMENT', resolverIntegrationHeader())
        ->get('/premium')
        ->assertOk();

    Event::assertDispatched(PaymentSettled::class);
});

it('resolver-returned facilitator is the one fed into PaymentEnforcer', function (): void {
    $fake = new FakeFacilitator();
    $wrapped = wrapForFacilitatorTest($fake);

    $resolver = new readonly class ($wrapped) implements FacilitatorResolver {
        public function __construct(private FacilitatorClient $client) {}

        public function resolve(mixed $context = null): FacilitatorClient
        {
            return $this->client;
        }
    };

    $this->app->instance(FacilitatorResolver::class, $resolver);

    $fake->rejectVerify('tenant-rejected');

    $this->withHeader('X-PAYMENT', resolverIntegrationHeader())
        ->get('/premium')
        ->assertStatus(402);

    expect($fake->verifyCalls())->toHaveCount(1);
});

it('custom-resolver-returned facilitator still fires PaymentSettled events', function (): void {
    Event::fake([PaymentSettled::class, PaymentRejected::class]);

    $fake = new FakeFacilitator();
    $wrapped = wrapForFacilitatorTest($fake);

    $resolver = new readonly class ($wrapped) implements FacilitatorResolver {
        public function __construct(private FacilitatorClient $client) {}

        public function resolve(mixed $context = null): FacilitatorClient
        {
            return $this->client;
        }
    };

    $this->app->instance(FacilitatorResolver::class, $resolver);

    $this->withHeader('X-PAYMENT', resolverIntegrationHeader())
        ->get('/premium')
        ->assertOk();

    Event::assertDispatched(PaymentSettled::class);
});

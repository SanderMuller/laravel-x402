<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Facilitator\DispatchingFacilitator;
use X402\Laravel\Facilitator\DispatchingFacilitatorFactory;
use X402\Laravel\Support\PaymentContextRegistry;
use X402\Laravel\Tests\TestCase;

pest()
    ->extend(TestCase::class)
    ->in('Feature');
uses()->in('Arch');

pest()->tia()->locally();

function wrapForFacilitatorTest(FacilitatorClient $inner): DispatchingFacilitator
{
    return DispatchingFacilitatorFactory::wrap(
        inner: $inner,
        events: resolve(Dispatcher::class),
        context: resolve(PaymentContextRegistry::class),
        container: app(),
    );
}

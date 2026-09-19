<?php

declare(strict_types=1);

use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Facades\X402;
use X402\Laravel\Facilitator\ConfiguredFacilitatorResolver;
use X402\Laravel\Facilitator\DispatchingFacilitator;
use X402\Laravel\Facilitator\FacilitatorResolver;
use X402\Laravel\Testing\FakeFacilitatorResolver;
use X402\Testing\FakeFacilitator;

it('binds FacilitatorResolver to ConfiguredFacilitatorResolver by default', function (): void {
    $resolver = $this->app->make(FacilitatorResolver::class);

    expect($resolver)->toBeInstanceOf(ConfiguredFacilitatorResolver::class);
});

it('default resolver returns the bound DispatchingFacilitator-wrapped client', function (): void {
    $resolver = $this->app->make(FacilitatorResolver::class);

    $client = $resolver->resolve();

    expect($client)->toBeInstanceOf(DispatchingFacilitator::class)
        ->toBe($this->app->make(FacilitatorClient::class));
});

it('default resolver returns the same instance regardless of context arg', function (): void {
    $resolver = $this->app->make(FacilitatorResolver::class);

    expect($resolver->resolve())->toBe($resolver->resolve())
        ->and($resolver->resolve('tenant-a'))->toBe($resolver->resolve('tenant-b'));
});

it('FakeFacilitatorResolver returns the wrapped facilitator', function (): void {
    $wrapped = wrapForFacilitatorTest(new FakeFacilitator());

    $resolver = new FakeFacilitatorResolver($wrapped);

    expect($resolver->resolve())->toBe($wrapped);
});

it('DispatchingFacilitatorFactory::wrap returns a wrapper, not the inner facilitator', function (): void {
    $inner = new FakeFacilitator();
    $wrapped = wrapForFacilitatorTest($inner);

    expect($wrapped)->not->toBe($inner);
});

it('X402::fake() swaps both FacilitatorClient and FacilitatorResolver bindings', function (): void {
    $fake = X402::fake();

    $client = $this->app->make(FacilitatorClient::class);
    expect($client)->toBeInstanceOf(DispatchingFacilitator::class);

    $resolver = $this->app->make(FacilitatorResolver::class);
    expect($resolver)->toBeInstanceOf(FakeFacilitatorResolver::class)
        ->and($resolver->resolve())
        ->toBe($client)
        ->and($fake->verifyCalls())
        ->toBeEmpty();
});

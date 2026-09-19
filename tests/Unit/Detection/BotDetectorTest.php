<?php

declare(strict_types=1);

use X402\Server\BotDetector;

it('returns false for empty user agent', function (): void {
    expect(new BotDetector()->isBot(''))->toBeFalse();
});

it('detects known AI agents case-insensitively', function (string $ua): void {
    expect(new BotDetector()->isBot($ua))->toBeTrue();
})->with([
    'GPTBot' => ['Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)'],
    'ClaudeBot' => ['Mozilla/5.0 (compatible; ClaudeBot/1.0)'],
    'lowercase claudebot' => ['claudebot/2.0'],
    'PerplexityBot' => ['Mozilla/5.0 PerplexityBot'],
    'CCBot' => ['CCBot/2.0 (https://commoncrawl.org/faq/)'],
]);

it('does not flag normal browsers', function (string $ua): void {
    expect(new BotDetector()->isBot($ua))->toBeFalse();
})->with([
    'Chrome' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'],
    'Safari iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'],
    'curl' => ['curl/8.4.0'],
]);

it('honours extra_patterns', function (): void {
    $detector = new BotDetector(extra: ['MyCustomScraper']);

    expect($detector->isBot('Foo MyCustomScraper/1.0'))->toBeTrue()
        ->and($detector->isBot('Foo Generic/1.0'))->toBeFalse();
});

it('overrides defaults when patterns is set', function (): void {
    $detector = new BotDetector(patterns: ['OnlyThisOne']);

    expect($detector->isBot('GPTBot/1.0'))->toBeFalse()
        ->and($detector->isBot('OnlyThisOne/1.0'))->toBeTrue();
});

it('deduplicates patterns', function (): void {
    $detector = new BotDetector(patterns: ['A', 'B'], extra: ['A', 'C']);

    expect($detector->patterns())->toBe(['A', 'B', 'C']);
});

it('ignores empty pattern entries', function (): void {
    $detector = new BotDetector(patterns: ['']);

    expect($detector->isBot('anything at all'))->toBeFalse();
});

it('disables detection entirely when patterns is an empty array', function (): void {
    // Explicit `patterns: []` is the documented "detect nothing" override —
    // distinct from `patterns: null` which means "use defaults".
    $detector = new BotDetector(patterns: []);

    expect($detector->isBot('GPTBot/1.0'))->toBeFalse()
        ->and($detector->isBot('Mozilla/5.0 ClaudeBot'))->toBeFalse()
        ->and($detector->patterns())
        ->toBeEmpty();
});

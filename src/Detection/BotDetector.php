<?php

declare(strict_types=1);

namespace X402\Laravel\Detection;

use X402\Server\BotDetector as UpstreamBotDetector;

/**
 * Matches User-Agent strings against a list of known AI agents,
 * assistants, scrapers, and search crawlers. Used by the `x402.bots`
 * middleware to gate routes for bots while leaving humans untouched.
 *
 * @deprecated since 0.5.0; use {@see UpstreamBotDetector} from
 *             `sandermuller/php-x402` ^0.5 directly. This class is now
 *             a thin wrapper that delegates to the upstream
 *             implementation (the pattern list and matching logic both
 *             moved upstream verbatim). It will be removed in
 *             laravel-x402 0.6.0; rebind your service provider to
 *             {@see UpstreamBotDetector::class} when convenient.
 */
final readonly class BotDetector
{
    /**
     * @var list<string>
     */
    public const array DEFAULT_PATTERNS = UpstreamBotDetector::DEFAULT_PATTERNS;

    private UpstreamBotDetector $inner;

    /**
     * @param  list<string>|null  $patterns  Override the built-in list. Pass null to use defaults.
     * @param  list<string>  $extra  Additional patterns merged on top of the active list.
     */
    public function __construct(?array $patterns = null, array $extra = [])
    {
        $this->inner = new UpstreamBotDetector($patterns, $extra);
    }

    public function isBot(string $userAgent): bool
    {
        return $this->inner->isBot($userAgent);
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->inner->patterns();
    }
}

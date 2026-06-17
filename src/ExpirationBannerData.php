<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit;

/**
 * Data struct returned by ExpirationBanner::compute(). UI is the host's
 * problem — the package emits only structured values.
 *
 * - `daysRemaining` Whole days from "now" to current_period_end. May be 0
 *   (expires today) or negative (already past — but the computer normally
 *   won't return data in that case).
 * - `severity`      One of 'info'|'warning'|'urgent'|'final' so the host
 *   can pick styling without re-deriving from the day count.
 */
final class ExpirationBannerData
{
    public const SEV_INFO    = 'info';
    public const SEV_WARNING = 'warning';
    public const SEV_URGENT  = 'urgent';
    public const SEV_FINAL   = 'final';

    public function __construct(
        public readonly int $daysRemaining,
        public readonly string $severity,
    ) {
    }
}

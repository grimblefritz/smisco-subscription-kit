<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit;

/**
 * Computes whether/when to show an expiration banner for a subscription
 * that's set to end at the current period boundary.
 *
 * Returns ExpirationBannerData when ALL of the following hold:
 *   - subscription is non-null
 *   - cancel_at_period_end is true
 *   - current_period_end is non-null
 *   - the computed days-remaining matches one of the configured trigger days
 *
 * Otherwise returns null (no banner). The trigger-day list lets each host
 * pick its nag cadence; SPV uses [10, 7, 4, 2, 0]. Severity is derived
 * from the day count via fixed thresholds.
 *
 * `nowEpoch` parameter on compute() is for deterministic tests; real
 * callers leave it null to use time().
 */
final class ExpirationBanner
{
    /** @var list<int> */
    private array $triggerDays;

    /**
     * @param list<int> $triggerDays Days-remaining values that should fire a banner.
     *                               Default mirrors SPV's nag cadence.
     */
    public function __construct(array $triggerDays = [10, 7, 4, 2, 0])
    {
        // Defensive copy + dedupe; we read it later via in_array.
        $clean = [];
        foreach ($triggerDays as $d) {
            if (!is_int($d)) {
                throw new \InvalidArgumentException('triggerDays must be a list of ints');
            }
            if (!in_array($d, $clean, true)) $clean[] = $d;
        }
        $this->triggerDays = $clean;
    }

    public function compute(?SubscriptionState $sub, ?int $nowEpoch = null): ?ExpirationBannerData
    {
        if ($sub === null) {
            return null;
        }
        if (!$sub->cancelAtPeriodEnd) {
            return null;
        }
        if ($sub->currentPeriodEnd === null) {
            return null;
        }

        $now  = $nowEpoch ?? time();
        $days = (int)floor(($sub->currentPeriodEnd - $now) / 86400);

        if (!in_array($days, $this->triggerDays, true)) {
            return null;
        }

        return new ExpirationBannerData(
            daysRemaining: $days,
            severity:      $this->severityFor($days),
        );
    }

    /**
     * Always-on companion to compute(): the same days-remaining calculation,
     * but returns the number regardless of whether it falls on a trigger day.
     * Useful for UI that wants to display a precise countdown next to (or
     * instead of) the banner. Returns null on the same disqualifications
     * (sub missing, cancel_at_period_end off, no period_end).
     */
    public function daysRemaining(?SubscriptionState $sub, ?int $nowEpoch = null): ?int
    {
        if ($sub === null || !$sub->cancelAtPeriodEnd || $sub->currentPeriodEnd === null) {
            return null;
        }
        $now = $nowEpoch ?? time();
        return (int)floor(($sub->currentPeriodEnd - $now) / 86400);
    }

    private function severityFor(int $days): string
    {
        if ($days <= 0) return ExpirationBannerData::SEV_FINAL;
        if ($days <= 2) return ExpirationBannerData::SEV_URGENT;
        if ($days <= 4) return ExpirationBannerData::SEV_WARNING;
        return ExpirationBannerData::SEV_INFO;
    }
}

<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit\Tests;

use PHPUnit\Framework\TestCase;
use Simnuxco\SubscriptionKit\ExpirationBanner;
use Simnuxco\SubscriptionKit\ExpirationBannerData;
use Simnuxco\SubscriptionKit\SubscriptionState;

final class ExpirationBannerTest extends TestCase
{
    private const NOW = 1700000000;

    /**
     * Build a sub whose current_period_end is $daysFromNow days after self::NOW.
     */
    private function subEndingInDays(int $daysFromNow, bool $cancelAtPeriodEnd = true): SubscriptionState
    {
        return new SubscriptionState(
            stripeSubscriptionId: 'sub_abc',
            stripeCustomerId:     'cus_xyz',
            skuCode:              'monthly_oneoff',
            status:               'active',
            currentPeriodStart:   self::NOW - 86400 * 5,
            currentPeriodEnd:     self::NOW + 86400 * $daysFromNow,
            cancelAtPeriodEnd:    $cancelAtPeriodEnd,
        );
    }

    public function test_null_subscription_returns_null(): void
    {
        $banner = new ExpirationBanner();
        $this->assertNull($banner->compute(null, self::NOW));
    }

    public function test_no_cancel_at_period_end_returns_null(): void
    {
        $banner = new ExpirationBanner();
        $sub = $this->subEndingInDays(7, cancelAtPeriodEnd: false);
        $this->assertNull($banner->compute($sub, self::NOW));
    }

    public function test_no_period_end_returns_null(): void
    {
        $sub = new SubscriptionState('sub_a', 'cus_a', 'm', 'active', null, null, true);
        $banner = new ExpirationBanner();
        $this->assertNull($banner->compute($sub, self::NOW));
    }

    public function test_fires_on_each_default_trigger_day(): void
    {
        $banner = new ExpirationBanner();
        foreach ([10, 7, 4, 2, 0] as $d) {
            $data = $banner->compute($this->subEndingInDays($d), self::NOW);
            $this->assertNotNull($data, "Expected banner at $d days");
            $this->assertSame($d, $data->daysRemaining);
        }
    }

    public function test_silent_on_off_trigger_days(): void
    {
        $banner = new ExpirationBanner();
        foreach ([14, 11, 9, 8, 6, 5, 3, 1, -1] as $d) {
            $this->assertNull(
                $banner->compute($this->subEndingInDays($d), self::NOW),
                "Did not expect banner at $d days"
            );
        }
    }

    public function test_custom_trigger_days(): void
    {
        $banner = new ExpirationBanner([14, 7, 1]);
        $this->assertNotNull($banner->compute($this->subEndingInDays(14), self::NOW));
        $this->assertNotNull($banner->compute($this->subEndingInDays(7),  self::NOW));
        $this->assertNotNull($banner->compute($this->subEndingInDays(1),  self::NOW));
        $this->assertNull($banner->compute($this->subEndingInDays(10), self::NOW));
        $this->assertNull($banner->compute($this->subEndingInDays(0),  self::NOW));
    }

    public function test_severity_bands(): void
    {
        $banner = new ExpirationBanner();
        $this->assertSame(ExpirationBannerData::SEV_INFO,    $banner->compute($this->subEndingInDays(10), self::NOW)->severity);
        $this->assertSame(ExpirationBannerData::SEV_INFO,    $banner->compute($this->subEndingInDays(7),  self::NOW)->severity);
        $this->assertSame(ExpirationBannerData::SEV_WARNING, $banner->compute($this->subEndingInDays(4),  self::NOW)->severity);
        $this->assertSame(ExpirationBannerData::SEV_URGENT,  $banner->compute($this->subEndingInDays(2),  self::NOW)->severity);
        $this->assertSame(ExpirationBannerData::SEV_FINAL,   $banner->compute($this->subEndingInDays(0),  self::NOW)->severity);
    }

    public function test_daysRemaining_always_on_when_qualifies(): void
    {
        $banner = new ExpirationBanner();
        $this->assertSame(15, $banner->daysRemaining($this->subEndingInDays(15), self::NOW));
        $this->assertSame(3,  $banner->daysRemaining($this->subEndingInDays(3),  self::NOW));
        $this->assertSame(0,  $banner->daysRemaining($this->subEndingInDays(0),  self::NOW));
    }

    public function test_daysRemaining_null_when_disqualified(): void
    {
        $banner = new ExpirationBanner();
        $this->assertNull($banner->daysRemaining(null, self::NOW));
        $this->assertNull($banner->daysRemaining($this->subEndingInDays(5, cancelAtPeriodEnd: false), self::NOW));
        $this->assertNull($banner->daysRemaining(
            new SubscriptionState('sub_a', 'cus_a', 'm', 'active', null, null, true),
            self::NOW
        ));
    }

    public function test_invalid_trigger_days_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExpirationBanner(['nope']); // not ints
    }
}

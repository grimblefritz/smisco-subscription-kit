<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit\Tests;

use PHPUnit\Framework\TestCase;
use Simnuxco\SubscriptionKit\SkuConfig;
use Simnuxco\SubscriptionKit\UnknownSkuException;

/**
 * Mirrors the SPV 4-SKU shape (two recurring, two one-off — one-off = a
 * subscription that auto-cancels at period end). The two-app variant
 * (trial_days) is exercised in the "trial" cases.
 */
final class SkuConfigTest extends TestCase
{
    private function spvShape(): array
    {
        return [
            'monthly_recurring' => ['price_id' => 'price_mrec', 'is_oneoff' => false, 'label' => 'Monthly'],
            'yearly_recurring'  => ['price_id' => 'price_yrec', 'is_oneoff' => false, 'label' => 'Yearly'],
            'monthly_oneoff'    => ['price_id' => 'price_mone', 'is_oneoff' => true,  'label' => 'Monthly (1x)'],
            'yearly_oneoff'     => ['price_id' => 'price_yone', 'is_oneoff' => true,  'label' => 'Yearly (1x)'],
        ];
    }

    public function test_has_and_codes(): void
    {
        $cfg = SkuConfig::fromArray($this->spvShape());
        $this->assertTrue($cfg->has('monthly_recurring'));
        $this->assertFalse($cfg->has('bogus'));
        $this->assertSame(
            ['monthly_recurring', 'yearly_recurring', 'monthly_oneoff', 'yearly_oneoff'],
            $cfg->codes()
        );
    }

    public function test_priceId_lookup(): void
    {
        $cfg = SkuConfig::fromArray($this->spvShape());
        $this->assertSame('price_mrec', $cfg->priceId('monthly_recurring'));
        $this->assertSame('price_yone', $cfg->priceId('yearly_oneoff'));
    }

    public function test_priceId_unknown_throws(): void
    {
        $cfg = SkuConfig::fromArray($this->spvShape());
        $this->expectException(UnknownSkuException::class);
        $cfg->priceId('not_a_sku');
    }

    public function test_isOneoff_and_label_defaults(): void
    {
        $cfg = SkuConfig::fromArray($this->spvShape());
        $this->assertFalse($cfg->isOneoff('monthly_recurring'));
        $this->assertTrue($cfg->isOneoff('monthly_oneoff'));
        $this->assertSame('Monthly', $cfg->label('monthly_recurring'));
    }

    public function test_mode_defaults_to_subscription(): void
    {
        $cfg = SkuConfig::fromArray($this->spvShape());
        $this->assertSame('subscription', $cfg->mode('monthly_recurring'));
        $this->assertSame('subscription', $cfg->mode('monthly_oneoff'));
    }

    public function test_trial_days_null_by_default(): void
    {
        $cfg = SkuConfig::fromArray($this->spvShape());
        $this->assertNull($cfg->trialDays('monthly_recurring'));
    }

    public function test_trial_days_when_set(): void
    {
        $cfg = SkuConfig::fromArray([
            'starter' => ['price_id' => 'price_starter', 'trial_days' => 14, 'label' => 'Starter'],
        ]);
        $this->assertSame(14, $cfg->trialDays('starter'));
    }

    public function test_codeForPriceId(): void
    {
        $cfg = SkuConfig::fromArray($this->spvShape());
        $this->assertSame('yearly_oneoff',     $cfg->codeForPriceId('price_yone'));
        $this->assertSame('monthly_recurring', $cfg->codeForPriceId('price_mrec'));
        $this->assertNull($cfg->codeForPriceId('price_does_not_exist'));
    }

    public function test_all_returns_normalized_shape(): void
    {
        $cfg = SkuConfig::fromArray([
            'a' => ['price_id' => 'price_a'], // mode, is_oneoff, trial_days, label all defaulted
        ]);
        $this->assertSame([
            'a' => [
                'price_id'   => 'price_a',
                'mode'       => 'subscription',
                'is_oneoff'  => false,
                'trial_days' => null,
                'label'      => 'a',
            ],
        ], $cfg->all());
    }

    public function test_empty_code_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SkuConfig(['' => ['price_id' => 'price_x']]);
    }

    public function test_missing_price_id_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SkuConfig(['a' => ['label' => 'No price']]);
    }

    public function test_invalid_mode_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SkuConfig(['a' => ['price_id' => 'price_a', 'mode' => 'rental']]);
    }

    public function test_payment_mode_accepted(): void
    {
        $cfg = SkuConfig::fromArray([
            'a' => ['price_id' => 'price_a', 'mode' => 'payment'],
        ]);
        $this->assertSame('payment', $cfg->mode('a'));
    }
}

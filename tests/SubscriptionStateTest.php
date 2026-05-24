<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit\Tests;

use PHPUnit\Framework\TestCase;
use Simnuxco\SubscriptionKit\SubscriptionState;
use Stripe\SearchResult;

final class SubscriptionStateTest extends TestCase
{
    public function test_fromArray_snake_case(): void
    {
        $row = [
            'stripe_subscription_id' => 'sub_abc',
            'stripe_customer_id'     => 'cus_xyz',
            'sku_code'               => 'monthly_recurring',
            'status'                 => 'active',
            'current_period_start'   => 1700000000,
            'current_period_end'     => 1702592000,
            'cancel_at_period_end'   => 0,
        ];
        $s = SubscriptionState::fromArray($row);
        $this->assertSame('sub_abc',           $s->stripeSubscriptionId);
        $this->assertSame('cus_xyz',           $s->stripeCustomerId);
        $this->assertSame('monthly_recurring', $s->skuCode);
        $this->assertSame('active',            $s->status);
        $this->assertSame(1700000000,          $s->currentPeriodStart);
        $this->assertSame(1702592000,          $s->currentPeriodEnd);
        $this->assertFalse($s->cancelAtPeriodEnd);
    }

    public function test_fromArray_handles_string_integers_and_truthy_cancel(): void
    {
        $row = [
            'stripe_subscription_id' => 'sub_abc',
            'stripe_customer_id'     => 'cus_xyz',
            'sku_code'               => 'monthly_oneoff',
            'status'                 => 'active',
            'current_period_start'   => '1700000000',
            'current_period_end'     => '1702592000',
            'cancel_at_period_end'   => '1',
        ];
        $s = SubscriptionState::fromArray($row);
        $this->assertSame(1700000000, $s->currentPeriodStart);
        $this->assertSame(1702592000, $s->currentPeriodEnd);
        $this->assertTrue($s->cancelAtPeriodEnd);
    }

    public function test_fromArray_nulls_period_when_blank(): void
    {
        $row = [
            'stripe_subscription_id' => 'sub_abc',
            'stripe_customer_id'     => 'cus_xyz',
            'sku_code'               => 'monthly_recurring',
            'status'                 => 'incomplete',
            'current_period_start'   => null,
            'current_period_end'     => '',
            'cancel_at_period_end'   => 0,
        ];
        $s = SubscriptionState::fromArray($row);
        $this->assertNull($s->currentPeriodStart);
        $this->assertNull($s->currentPeriodEnd);
    }

    public function test_toArray_roundtrip(): void
    {
        $s = new SubscriptionState(
            stripeSubscriptionId: 'sub_abc',
            stripeCustomerId:     'cus_xyz',
            skuCode:              'yearly_recurring',
            status:               'active',
            currentPeriodStart:   1700000000,
            currentPeriodEnd:     1731536000,
            cancelAtPeriodEnd:    true,
        );
        $arr = $s->toArray();
        $this->assertSame([
            'stripe_subscription_id' => 'sub_abc',
            'stripe_customer_id'     => 'cus_xyz',
            'sku_code'               => 'yearly_recurring',
            'status'                 => 'active',
            'current_period_start'   => 1700000000,
            'current_period_end'     => 1731536000,
            'cancel_at_period_end'   => 1,
        ], $arr);

        // Round trip
        $s2 = SubscriptionState::fromArray($arr);
        $this->assertEquals($s, $s2);
    }

    public function test_isActive(): void
    {
        $active   = new SubscriptionState('sub_a', 'cus_a', 'm', 'active',   null, null, false);
        $trialing = new SubscriptionState('sub_a', 'cus_a', 'm', 'trialing', null, null, false);
        $past_due = new SubscriptionState('sub_a', 'cus_a', 'm', 'past_due', null, null, false);
        $canceled = new SubscriptionState('sub_a', 'cus_a', 'm', 'canceled', null, null, false);

        $this->assertTrue($active->isActive());
        $this->assertTrue($trialing->isActive());
        $this->assertFalse($past_due->isActive());
        $this->assertFalse($canceled->isActive());
    }

    public function test_fromStripeSubscription_reads_period_off_items(): void
    {
        // Hand-rolled fake of the relevant Stripe Subscription shape — no
        // SDK calls. fromStripeSubscription only reads ->id, ->customer,
        // ->status, ->cancel_at_period_end, ->metadata->sku_code, and
        // ->items->data[0]->current_period_*.
        $item = new \stdClass();
        $item->current_period_start = 1700000000;
        $item->current_period_end   = 1702592000;

        $items = new \stdClass();
        $items->data = [$item];

        $metadata = new \stdClass();
        $metadata->sku_code = 'monthly_recurring';

        $sub = $this->fakeStripeSubscription([
            'id'                   => 'sub_abc',
            'customer'             => 'cus_xyz',
            'status'               => 'active',
            'cancel_at_period_end' => false,
            'items'                => $items,
            'metadata'             => $metadata,
        ]);

        $s = SubscriptionState::fromStripeSubscription($sub);
        $this->assertSame('sub_abc',           $s->stripeSubscriptionId);
        $this->assertSame('cus_xyz',           $s->stripeCustomerId);
        $this->assertSame('monthly_recurring', $s->skuCode);
        $this->assertSame(1700000000,          $s->currentPeriodStart);
        $this->assertSame(1702592000,          $s->currentPeriodEnd);
        $this->assertFalse($s->cancelAtPeriodEnd);
    }

    public function test_fromStripeSubscription_falls_back_to_resolved_sku(): void
    {
        $item = new \stdClass();
        $item->current_period_end = 1702592000;
        $items = new \stdClass();
        $items->data = [$item];

        // metadata.sku_code is unset → resolvedSkuCode wins
        $metadata = new \stdClass();

        $sub = $this->fakeStripeSubscription([
            'id'                   => 'sub_abc',
            'customer'             => 'cus_xyz',
            'status'               => 'active',
            'cancel_at_period_end' => false,
            'items'                => $items,
            'metadata'             => $metadata,
        ]);

        $s = SubscriptionState::fromStripeSubscription($sub, 'yearly_oneoff');
        $this->assertSame('yearly_oneoff', $s->skuCode);
    }

    /**
     * Build a stand-in for \Stripe\Subscription that exposes the subset of
     * fields fromStripeSubscription reads. constructFrom() is the SDK's
     * canonical "build from a values array" entry point — it accepts `id`
     * inside the array and routes all properties through StripeObject's
     * value map without triggering an HTTP fetch.
     */
    private function fakeStripeSubscription(array $props): \Stripe\Subscription
    {
        return \Stripe\Subscription::constructFrom($props);
    }
}

<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit;

use Stripe\Subscription as StripeSubscription;

/**
 * Normalized snapshot of a Stripe Subscription. Pure value object — no DB
 * or Stripe API calls. Hosts persist these via their SubscriptionStore;
 * the package consumes them through AccessGate, ExpirationBanner, etc.
 *
 * Period timestamps are unix epoch seconds. Stripe API 2025-08-27 moved
 * `current_period_start` / `current_period_end` off the top-level
 * Subscription object onto each SubscriptionItem; fromStripeSubscription()
 * reads them from `items->data[0]` accordingly.
 */
final class SubscriptionState
{
    /**
     * @param string  $stripeSubscriptionId
     * @param string  $stripeCustomerId
     * @param string  $skuCode               Resolved SKU code (caller may pass null at the boundary and resolve via SkuConfig::codeForPriceId)
     * @param string  $status                Stripe sub status: 'active'|'trialing'|'past_due'|'canceled'|'incomplete'|'incomplete_expired'|'unpaid'
     * @param ?int    $currentPeriodStart    Unix timestamp, or null if Stripe didn't report one yet
     * @param ?int    $currentPeriodEnd      Unix timestamp, or null if Stripe didn't report one yet
     * @param bool    $cancelAtPeriodEnd
     */
    public function __construct(
        public readonly string $stripeSubscriptionId,
        public readonly string $stripeCustomerId,
        public readonly string $skuCode,
        public readonly string $status,
        public readonly ?int $currentPeriodStart,
        public readonly ?int $currentPeriodEnd,
        public readonly bool $cancelAtPeriodEnd,
    ) {
    }

    /**
     * Build from a live Stripe Subscription. $resolvedSkuCode lets the caller
     * pass the SKU resolved out-of-band (from session.metadata or via
     * SkuConfig::codeForPriceId on the subscription's price); when null we
     * fall back to subscription.metadata.sku_code.
     */
    public static function fromStripeSubscription(StripeSubscription $sub, ?string $resolvedSkuCode = null): self
    {
        $item = $sub->items->data[0] ?? null;
        $period_start = $item ? ($item->current_period_start ?? null) : null;
        $period_end   = $item ? ($item->current_period_end   ?? null) : null;

        $sku_code = $resolvedSkuCode
            ?? ($sub->metadata->sku_code ?? null)
            ?? '';

        return new self(
            stripeSubscriptionId: (string)$sub->id,
            stripeCustomerId:     (string)$sub->customer,
            skuCode:              (string)$sku_code,
            status:               (string)$sub->status,
            currentPeriodStart:   $period_start === null ? null : (int)$period_start,
            currentPeriodEnd:     $period_end   === null ? null : (int)$period_end,
            cancelAtPeriodEnd:    (bool)$sub->cancel_at_period_end,
        );
    }

    /**
     * Build from a DB row. Tolerant to either snake_case or camelCase keys
     * so a host's existing schema column names can flow through verbatim.
     *
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $get = static function (string $snake) use ($row) {
            $camel = lcfirst(str_replace('_', '', ucwords($snake, '_')));
            return $row[$snake] ?? $row[$camel] ?? null;
        };

        $period_start = $get('current_period_start');
        $period_end   = $get('current_period_end');

        return new self(
            stripeSubscriptionId: (string)($get('stripe_subscription_id') ?? ''),
            stripeCustomerId:     (string)($get('stripe_customer_id')     ?? ''),
            skuCode:              (string)($get('sku_code')               ?? ''),
            status:               (string)($get('status')                 ?? ''),
            currentPeriodStart:   $period_start === null || $period_start === '' ? null : (int)$period_start,
            currentPeriodEnd:     $period_end   === null || $period_end   === '' ? null : (int)$period_end,
            cancelAtPeriodEnd:    (bool)$get('cancel_at_period_end'),
        );
    }

    /**
     * Round-trip back to an associative array. Keys match the SPV
     * `subscriptions` table column names so an SPV store can persist
     * the array directly.
     *
     * @return array{stripe_subscription_id:string,stripe_customer_id:string,sku_code:string,status:string,current_period_start:?int,current_period_end:?int,cancel_at_period_end:int}
     */
    public function toArray(): array
    {
        return [
            'stripe_subscription_id' => $this->stripeSubscriptionId,
            'stripe_customer_id'     => $this->stripeCustomerId,
            'sku_code'               => $this->skuCode,
            'status'                 => $this->status,
            'current_period_start'   => $this->currentPeriodStart,
            'current_period_end'     => $this->currentPeriodEnd,
            'cancel_at_period_end'   => $this->cancelAtPeriodEnd ? 1 : 0,
        ];
    }

    /**
     * Convenience: is this subscription currently in a state that grants
     * access? Mirrors the access decision used by AccessGate.
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true);
    }
}

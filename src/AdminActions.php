<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit;

use Stripe\Subscription as StripeSubscription;

/**
 * Stripe-touching admin verbs, centralized so hosts don't reimplement
 * them per app. Each method:
 *
 *   1. Talks to Stripe (read or mutate)
 *   2. Persists the result via SubscriptionStore / UserStore
 *   3. Optionally writes one AuditLogger row
 *
 * What the package does NOT own here:
 *
 *   - Authorization (who can call these — host gates before constructing AdminActions)
 *   - Self-target guards (host enforces; admin-can't-edit-self is policy)
 *   - Note-length validation (host enforces before calling)
 *   - DB transaction management — methods are individually consistent but
 *     not bracketed; if the host wants tighter atomicity (e.g. multi-action
 *     audit chain), wrap calls externally.
 *
 * AdminActions is stateless across mutations; one instance per request is
 * fine. The `adminUserId` constructor parameter pins "who's acting" for
 * the audit-log writes.
 */
final class AdminActions
{
    private AuditLogger $audit;

    public function __construct(
        private readonly StripeClient $client,
        private readonly SkuConfig $skus,
        private readonly SubscriptionStore $subscriptions,
        private readonly UserStore $users,
        private readonly int $adminUserId,
        ?AuditLogger $audit = null,
    ) {
        $this->audit = $audit ?? new NullAuditLogger();
    }

    /**
     * Re-fetch the subscription from Stripe and upsert the local copy.
     * Returns the freshly-synced SubscriptionState.
     *
     * @param int $localTargetId Host's local id for the audit row's target_id
     *                           field (e.g. SPV's subscriptions.id). May
     *                           differ from the Stripe subscription id.
     */
    public function syncSubscription(
        string $stripeSubscriptionId,
        int $userId,
        ?int $localTargetId = null,
        ?string $note = null,
    ): SubscriptionState {
        $before = $this->subscriptions->findByStripeSubscriptionId($stripeSubscriptionId);
        $live   = StripeSubscription::retrieve($stripeSubscriptionId);

        // SKU resolution precedence (mirrors the webhook's
        // stripeWebhookResolveSkuFromSubscription fallback chain):
        //   1. Stripe metadata.sku_code (the canonical write path —
        //      our CheckoutService writes this on every session)
        //   2. Reverse-lookup price_id → sku_code via SkuConfig
        //   3. Preserve the locally-known sku_code (last-ditch for subs
        //      created outside the host app, e.g. a Stripe Dashboard
        //      admin re-creating a sub)
        //
        // SubscriptionState::fromStripeSubscription handles step 1 when
        // we pass null. We only compute steps 2-3 when metadata is empty.
        $resolved = null;
        $metadataCode = $live->metadata->sku_code ?? '';
        if ($metadataCode === '') {
            $priceId = $live->items->data[0]->price->id ?? null;
            if ($priceId !== null) {
                $resolved = $this->skus->codeForPriceId($priceId);
            }
            if ($resolved === null && $before !== null) {
                $resolved = $before->skuCode;
            }
        }
        $state = SubscriptionState::fromStripeSubscription($live, $resolved);
        $this->subscriptions->upsert($state, $userId);

        $this->audit->log(
            $this->adminUserId,
            'subscription.sync',
            'subscriptions',
            $localTargetId,
            $before === null ? null : [
                'status'               => $before->status,
                'cancel_at_period_end' => $before->cancelAtPeriodEnd ? 1 : 0,
                'current_period_end'   => $before->currentPeriodEnd,
            ],
            [
                'status'               => $state->status,
                'cancel_at_period_end' => $state->cancelAtPeriodEnd ? 1 : 0,
                'current_period_end'   => $state->currentPeriodEnd,
            ],
            $note,
        );

        return $state;
    }

    /**
     * Schedule the subscription to cancel at the current period boundary.
     * Mirrors what the customer-portal cancel button does — user keeps
     * access through the paid period; Stripe then re-fires
     * customer.subscription.updated which mirrors the cancel flag through
     * the host's webhook.
     *
     * Returns the local-side SubscriptionState after the cancel flag is
     * mirrored locally (without waiting for the webhook).
     */
    public function cancelSubscription(
        string $stripeSubscriptionId,
        ?int $localTargetId = null,
        ?string $note = null,
    ): SubscriptionState {
        $before = $this->subscriptions->findByStripeSubscriptionId($stripeSubscriptionId);
        if ($before === null) {
            throw new \RuntimeException(
                "AdminActions::cancelSubscription: no local subscription for id '$stripeSubscriptionId'"
            );
        }

        StripeSubscription::update($stripeSubscriptionId, [
            'cancel_at_period_end' => true,
        ]);

        // Mirror the flag locally so the admin UI shows it immediately
        // rather than waiting for the webhook to round-trip.
        $after = new SubscriptionState(
            stripeSubscriptionId: $before->stripeSubscriptionId,
            stripeCustomerId:     $before->stripeCustomerId,
            skuCode:              $before->skuCode,
            status:               $before->status,
            currentPeriodStart:   $before->currentPeriodStart,
            currentPeriodEnd:     $before->currentPeriodEnd,
            cancelAtPeriodEnd:    true,
        );
        // We don't have user_id here directly — but the row already exists,
        // so upsert lands on the ON CONFLICT path and user_id is preserved.
        // We pass 0 as a sentinel; the impl's UPDATE branch never reads it.
        $this->subscriptions->upsert($after, 0);

        $this->audit->log(
            $this->adminUserId,
            'subscription.cancel',
            'subscriptions',
            $localTargetId,
            ['cancel_at_period_end' => $before->cancelAtPeriodEnd ? 1 : 0],
            ['cancel_at_period_end' => 1],
            $note,
        );

        return $after;
    }

    /**
     * Set the user's subscription-gate override marker. Hosts validate
     * the value before calling (SPV constrains to {'comp', 'admin'}).
     */
    public function setOverride(int $userId, string $value, ?string $note = null): void
    {
        $before = $this->users->getOverride($userId);
        $this->users->setOverride($userId, $value);

        $this->audit->log(
            $this->adminUserId,
            'user.set_override',
            'users',
            $userId,
            ['subscription_override' => $before],
            ['subscription_override' => $value],
            $note,
        );
    }

    /**
     * Clear the user's subscription-gate override.
     */
    public function clearOverride(int $userId, ?string $note = null): void
    {
        $before = $this->users->getOverride($userId);
        $this->users->setOverride($userId, null);

        $this->audit->log(
            $this->adminUserId,
            'user.clear_override',
            'users',
            $userId,
            ['subscription_override' => $before],
            ['subscription_override' => null],
            $note,
        );
    }
}

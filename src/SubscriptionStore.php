<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit;

/**
 * Host-implemented persistence boundary for subscription state. The
 * package never touches a database directly — hosts wire their own DB
 * code into an implementation of this interface and pass instances to
 * the AccessGate, WebhookReceiver, and AdminActions surfaces.
 *
 * All methods are sync (no futures). Implementations should keep them
 * cheap; the gate path runs on every authenticated request.
 *
 * Marker methods (`markEnded`, `onTrialEnding`) are keyed by Stripe
 * subscription id rather than customer id. Stripe customers can hold
 * multiple Subscription objects over time (cancel → re-subscribe), and
 * the events that drive these methods carry the specific subscription
 * id — using it directly avoids ambiguity when a customer has historical
 * cancellations alongside an active subscription.
 *
 * `onTrialEnding` corresponds to Stripe's `customer.subscription.trial_will_end`
 * event. Hosts that don't use trial-period SKUs can implement it as a
 * no-op; SPV's SkuConfig sets `trial_days: null` for all SKUs, so SPV's
 * implementation is a no-op.
 */
interface SubscriptionStore
{
    /**
     * Return the most recent subscription for the Stripe customer, or
     * null if none. "Most recent" = ordered by created_at desc with id
     * as the tiebreaker; the impl owns the exact tie semantics.
     */
    public function findByCustomerId(string $stripeCustomerId): ?SubscriptionState;

    /**
     * Return the most recent subscription for the host's user, or null
     * if none. Used by the gate path on every authenticated request.
     */
    public function findByUserId(int $userId): ?SubscriptionState;

    /**
     * Return the subscription whose stripe_subscription_id matches, or
     * null if none. The id is UNIQUE per Stripe Subscription so at most
     * one row matches. Used by admin verbs that operate by sub id.
     */
    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?SubscriptionState;

    /**
     * Upsert the subscription row. Implementations should key on
     * stripe_subscription_id (it's unique per Stripe Subscription) and
     * either INSERT-or-UPDATE atomically.
     *
     * The package supplies `$userId` separately because SubscriptionState
     * doesn't carry it — Stripe doesn't know the host's user id directly,
     * and the webhook receiver resolves it before calling here.
     */
    public function upsert(SubscriptionState $state, int $userId): void;

    /**
     * Mark the specific subscription as ended. Called from the webhook
     * dispatcher on `customer.subscription.deleted`. Typical implementation:
     * `UPDATE subscriptions SET status='canceled', updated_at=now WHERE stripe_subscription_id = ?`.
     */
    public function markEnded(string $stripeSubscriptionId): void;

    /**
     * Called from the webhook dispatcher on `customer.subscription.trial_will_end`.
     * Hosts using trial-period SKUs typically queue a notification email;
     * hosts that don't can implement as a no-op (PHP requires the
     * method to exist on the impl).
     */
    public function onTrialEnding(string $stripeSubscriptionId): void;
}

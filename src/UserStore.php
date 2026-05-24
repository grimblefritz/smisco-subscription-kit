<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit;

/**
 * Host-implemented persistence boundary for user state — the bits the
 * package needs to make access decisions and to drive the post-Checkout
 * pending→active transition.
 *
 * The status vocabulary is fixed at three values:
 *   - 'pending'   : user registered, hasn't completed initial Checkout
 *   - 'active'    : normal account
 *   - 'suspended' : admin-disabled (denied access regardless of subscription)
 *
 * `override` is the per-user subscription-gate bypass marker. AccessGate
 * short-circuits to 'allow' whenever this returns a non-empty string.
 * Vocabulary is host-defined but the package convention is 'comp'
 * (complimentary) or 'admin' (manual override for support cases).
 */
interface UserStore
{
    /**
     * Return the user's account status: 'pending' | 'active' | 'suspended'.
     */
    public function getStatus(int $userId): string;

    /**
     * Persist the new status. Called by the webhook dispatcher to flip
     * a buyer from 'pending' to 'active' on `checkout.session.completed`.
     */
    public function setStatus(int $userId, string $status): void;

    /**
     * Return the subscription-gate override marker for this user, or
     * null if none. AccessGate treats any non-empty return as a bypass.
     */
    public function getOverride(int $userId): ?string;

    /**
     * Set or clear the subscription-gate override. Pass null to clear.
     * Used by AdminActions::setOverride / clearOverride.
     */
    public function setOverride(int $userId, ?string $override): void;

    /**
     * Return the user's Stripe customer id, or null if they haven't
     * been created in Stripe yet. CheckoutService consults this before
     * creating a new Customer (idempotent reuse).
     */
    public function getStripeCustomerId(int $userId): ?string;

    /**
     * Persist a freshly-created Stripe customer id. Called by
     * CheckoutService after a `\Stripe\Customer::create` call.
     */
    public function setStripeCustomerId(int $userId, string $stripeCustomerId): void;

    /**
     * Webhook recovery path: resolve user_id from email when a Stripe
     * event arrives without our metadata (e.g. a subscription created
     * outside the host's app). Returns null if no user has that email.
     *
     * The email comparison is the host's responsibility — SPV does a
     * case-insensitive lookup on the normalized lowercased address.
     */
    public function findUserIdByEmail(string $email): ?int;
}

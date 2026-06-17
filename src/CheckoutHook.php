<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit;

use Stripe\Checkout\Session as CheckoutSession;

/**
 * Optional host-implemented hook for post-Checkout side effects that the
 * package doesn't own (because they're app-specific data-model concerns).
 *
 * SPV uses this to lazily create the `buyers` row when a buyer-typed user
 * completes their first Checkout. Other apps may use it to seed app data,
 * fire notifications, or enroll the user in an onboarding flow.
 *
 * Called by WebhookReceiver from inside `checkout.session.completed` after
 * the SubscriptionStore::upsert has persisted the subscription. Runs in
 * the same DB transaction the host wraps the receive in.
 */
interface CheckoutHook
{
    public function afterCheckoutCompleted(CheckoutSession $session, int $userId): void;
}

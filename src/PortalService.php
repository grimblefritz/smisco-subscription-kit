<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit;

use Stripe\BillingPortal\Session as PortalSession;

/**
 * Creates Stripe Billing Portal sessions. The Portal is the URL where
 * Stripe hosts the customer's self-service subscription management
 * (update payment method, view invoices, cancel). The caller passes a
 * return URL — Stripe redirects back when the user is done.
 *
 * Distinct service rather than a method on CheckoutService because:
 *   - Different prerequisite: requires an existing Stripe customer id
 *     (created by a prior Checkout). CheckoutService creates customers
 *     lazily; this one consults the UserStore and errors out clean if
 *     none exists.
 *   - Different consumers: SPV exposes /profile/'s "Manage subscription"
 *     button; CheckoutService drives /register/, /subscription/, etc.
 */
final class PortalService
{
    public function __construct(
        private readonly StripeClient $client,   // SDK init proof
        private readonly UserStore $users,
    ) {
    }

    /**
     * Build a Portal session and return its redirect URL.
     *
     * @throws \RuntimeException if the user has no Stripe customer id
     *         (they haven't completed an initial Checkout).
     */
    public function createSession(int $userId, string $returnUrl): string
    {
        $customerId = $this->users->getStripeCustomerId($userId);
        if ($customerId === null || $customerId === '') {
            throw new \RuntimeException(
                "PortalService::createSession: user $userId has no Stripe customer id; "
                . 'they need to complete Checkout first.'
            );
        }

        $session = PortalSession::create([
            'customer'   => $customerId,
            'return_url' => $returnUrl,
        ]);
        return (string)$session->url;
    }
}

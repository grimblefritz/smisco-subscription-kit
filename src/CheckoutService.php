<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit;

use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;

/**
 * Creates Stripe Checkout sessions for the host. Handles:
 *
 *   - SKU → Price ID resolution (via SkuConfig)
 *   - Stripe Customer get-or-create (via UserStore)
 *   - `mode: 'subscription'` vs `mode: 'payment'` (per SkuConfig)
 *   - `trial_period_days` injection when SkuConfig has trial_days set
 *   - Host-supplied success/cancel URLs and extra metadata
 *
 * What it does NOT do:
 *
 *   - Patch the resulting Stripe Subscription with `cancel_at_period_end`
 *     for one-off SKUs. That happens in the webhook (`checkout.session.completed`
 *     handler) because Stripe doesn't accept the flag at Checkout creation
 *     and we need the Subscription to exist first.
 *   - Persist anything to the SubscriptionStore. The Subscription doesn't
 *     exist yet — Checkout success creates it and the webhook upserts.
 */
final class CheckoutService
{
    public function __construct(
        private readonly StripeClient $client,         // SDK init proof
        private readonly SkuConfig $skus,
        private readonly UserStore $users,
    ) {
    }

    /**
     * Build a Checkout session and return its redirect URL.
     *
     * @param int                  $userId          Host user id (drives Customer get-or-create)
     * @param string               $email           User's email — required if Stripe needs to create a Customer
     * @param string               $name            User's display name (first + last), empty allowed
     * @param string               $skuCode         Key into SkuConfig
     * @param array<string,string> $extraMetadata   Merged into both session.metadata and subscription_data.metadata
     * @param string               $successUrl      Where Stripe redirects after a completed Checkout
     * @param string               $cancelUrl       Where Stripe redirects on user-cancel
     * @return string The Checkout session redirect URL
     */
    public function createSession(
        int $userId,
        string $email,
        string $name,
        string $skuCode,
        array $extraMetadata,
        string $successUrl,
        string $cancelUrl,
    ): string {
        if (!$this->skus->has($skuCode)) {
            throw new UnknownSkuException("CheckoutService: unknown sku '$skuCode'");
        }

        $customerId = $this->getOrCreateCustomer($userId, $email, $name);

        $metadata = array_merge($extraMetadata, [
            'user_id'  => (string)$userId,
            'sku_code' => $skuCode,
        ]);

        $params = [
            'customer'    => $customerId,
            'mode'        => $this->skus->mode($skuCode),
            'line_items'  => [[
                'price'    => $this->skus->priceId($skuCode),
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'metadata'    => $metadata,
        ];

        if ($params['mode'] === 'subscription') {
            $params['subscription_data'] = ['metadata' => $metadata];
            $trial = $this->skus->trialDays($skuCode);
            if ($trial !== null && $trial > 0) {
                $params['subscription_data']['trial_period_days'] = $trial;
            }
        }

        $session = CheckoutSession::create($params);
        return (string)$session->url;
    }

    /**
     * Read the user's Stripe customer id from the UserStore; if absent,
     * create a Stripe Customer + persist the id. Idempotent across calls
     * once the id is stored.
     */
    private function getOrCreateCustomer(int $userId, string $email, string $name): string
    {
        $existing = $this->users->getStripeCustomerId($userId);
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $name = trim($name);
        $customer = Customer::create([
            'email'    => $email,
            'name'     => $name !== '' ? $name : null,
            'metadata' => [
                'user_id' => (string)$userId,
            ],
        ]);

        $cid = (string)$customer->id;
        $this->users->setStripeCustomerId($userId, $cid);
        return $cid;
    }
}

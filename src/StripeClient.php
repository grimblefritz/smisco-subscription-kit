<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit;

use Stripe\Stripe;

/**
 * Initializes the Stripe SDK with the host's API key (and optional API
 * version pin) at construction. The Stripe SDK keeps its config in
 * static state (`\Stripe\Stripe::setApiKey`), so once a StripeClient is
 * constructed every subsequent Stripe SDK call uses the supplied key.
 *
 * Exists as an explicit type so service classes (CheckoutService,
 * PortalService, WebhookReceiver) can declare it as a constructor
 * parameter — "proof of init" without secret-key passing through every
 * surface. Construct once at boot, hand the instance to every service.
 *
 * Pinning an API version is recommended for production. Stripe ships
 * occasional breaking field moves (`current_period_*` moved off the
 * Subscription onto `items.data[N]` in 2025-08-27.basil — SPV already
 * accommodates that). Pinning prevents the next move from silently
 * breaking deployed code; bumping is a deliberate change.
 */
final class StripeClient
{
    public function __construct(string $secretKey, ?string $apiVersion = null)
    {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('StripeClient: secretKey is empty');
        }
        Stripe::setApiKey($secretKey);
        if ($apiVersion !== null && $apiVersion !== '') {
            Stripe::setApiVersion($apiVersion);
        }
    }
}

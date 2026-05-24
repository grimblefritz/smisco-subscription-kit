# simnuxco/subscription-kit

Composer library wrapping Stripe Checkout, Customer Portal, webhook receiver, and admin verbs behind host-implemented persistence interfaces.

**Status:** v0.1. Not yet on Packagist.

## What's in the package

Pure logic (no Stripe API, no DB):

| Class | Responsibility |
|-------|----------------|
| `SkuConfig` | Immutable SKU map: code → Stripe Price ID + mode + is-oneoff + trial-days + label. |
| `SubscriptionState` | Normalized snapshot of a Stripe Subscription. Builds from `\Stripe\Subscription`, a DB-row array, or directly via constructor. |
| `AccessGate` + `GateContext` | Decides `allow` / `pending` / `ended` / `no_subscription` for a user/subscription pair. |
| `ExpirationBanner` + `ExpirationBannerData` | Decides whether to show an end-of-period banner at a discrete trigger-day list. |
| `UnknownSkuException` | Raised by `SkuConfig` lookups for unknown codes. |

Stripe-touching services (each takes a `StripeClient` as a "proof of init" constructor parameter):

| Class | Responsibility |
|-------|----------------|
| `StripeClient` | Initializes the global Stripe SDK state at construction. |
| `CheckoutService` | Builds Stripe Checkout sessions. Customer get-or-create + metadata writing. |
| `PortalService` | Builds Stripe Billing Portal sessions. |
| `WebhookReceiver` | Verifies signature, idempotency-marks, dispatches the six relevant Stripe events, returns a `WebhookResult`. |
| `AdminActions` | Sync / cancel subscription, set / clear override. One audit-log row per mutation. |

Host-implemented interfaces (the package never touches a DB directly):

| Interface | Responsibility |
|-----------|----------------|
| `SubscriptionStore` | Query / upsert / mark-ended Subscriptions. |
| `UserStore` | Status, override marker, Stripe customer id, email lookup. |
| `EventIdempotencyStore` | One method: `recordEvent($id, $type)`. |
| `AuditLogger` (+ `NullAuditLogger` default) | Optional admin audit-trail. |
| `CheckoutHook` | Optional post-Checkout side-effects. |

## Requirements

- PHP 8.1+
- `stripe/stripe-php ^17`

## Install

The package isn't published. Consumers wire it via a Composer path repository:

```json
{
    "repositories": [
        {"type": "path", "url": "../../stripe-subscription-kit"}
    ],
    "require": {
        "simnuxco/subscription-kit": "@dev"
    }
}
```

Adjust the relative path to match your layout.

## Wire-up

```php
use Simnuxco\SubscriptionKit\{
    StripeClient, SkuConfig,
    CheckoutService, PortalService,
    AccessGate, ExpirationBanner,
    WebhookReceiver, AdminActions
};

$stripe = new StripeClient($secretKey, '2025-08-27.basil');
$skus   = new SkuConfig([...]);
$users  = new MyUserStore($db);              // host impl of UserStore
$subs   = new MySubscriptionStore($db);      // host impl of SubscriptionStore
$events = new MyEventIdempotencyStore($db);  // host impl of EventIdempotencyStore

$checkout = new CheckoutService($stripe, $skus, $users);
$portal   = new PortalService($stripe, $users);
$gate     = new AccessGate();
$banner   = new ExpirationBanner();
$webhook  = new WebhookReceiver($webhookSecret, $stripe, $skus, $events, $subs, $users);
$admin    = new AdminActions($stripe, $skus, $subs, $users, $adminUserId, $audit);
```

The host wraps every `$webhook->handle($payload, $signature)` call in its own DB transaction. The receiver does not own the transaction because it doesn't know the host's DB driver. The idempotency-mark-then-dispatch pattern only works atomically when the mark is in the same transaction as the side effects, so the host must own commit/rollback.

## Tests

```bash
./vendor/bin/phpunit                       # canonical
php tests/run.php                          # zero-vendor fallback
php tests/run.php tests/SkuConfigTest.php  # one file
```

Currently 44 tests / 102 assertions, all green via real PHPUnit (44/44 via the fallback runner). Coverage is limited to the pure-logic classes; the Stripe-touching services and host interfaces are not yet covered.

## License

Proprietary. Not yet open-sourced.

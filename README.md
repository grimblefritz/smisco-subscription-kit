# simnuxco/subscription-kit

A PHP library that wraps Stripe's subscription lifecycle — Checkout, Customer Portal, webhooks, access gating, and admin operations — behind host-implemented persistence interfaces. Your app supplies the database layer; the kit owns the Stripe logic.

**Requirements:** PHP 8.1+ and `stripe/stripe-php ^17`.

## Table of contents

- [What it does](#what-it-does)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Concepts](#concepts)
- [SKU configuration](#sku-configuration)
- [Checkout](#checkout)
- [Customer Portal](#customer-portal)
- [Webhooks](#webhooks)
- [Access gating](#access-gating)
- [Expiration banners](#expiration-banners)
- [Admin actions](#admin-actions)
- [Interfaces you implement](#interfaces-you-implement)
- [API reference](#api-reference)
- [Testing](#testing)
- [Design notes](#design-notes)
- [License](#license)

## What it does

Subscription Kit handles:

- **Checkout** — creates Stripe Checkout sessions, resolves SKUs to Price IDs, gets-or-creates Stripe Customers, writes metadata
- **Customer Portal** — creates Billing Portal sessions for subscriber self-service
- **Webhooks** — verifies signatures, deduplicates events, dispatches handlers for checkout completion, subscription lifecycle, invoice events
- **Access gating** — decides `allow` / `pending` / `ended` / `no_subscription` per request based on user status, subscription state, role, and override markers
- **Expiration banners** — computes when to show end-of-period warnings on a configurable trigger-day schedule
- **Admin operations** — sync, cancel, set/clear overrides with optional audit logging

What it does _not_ do:

- Touch your database — all persistence is behind interfaces you implement
- Own HTTP routing — you call the kit from your own controllers/endpoints
- Manage transactions — your webhook endpoint wraps calls in your own DB transaction

## Installation

The package is not on Packagist. Install it as a Composer [path repository](https://getcomposer.org/doc/05-repositories.md#path) pointing at your local checkout.

Clone the repository:

```bash
git clone https://github.com/grimblefritz/stripe-subscription-kit.git
```

In your app's `composer.json`, add the repository and require the package:

```json
{
    "repositories": [
        {"type": "path", "url": "../stripe-subscription-kit/public"}
    ],
    "require": {
        "simnuxco/subscription-kit": "@dev"
    }
}
```

Adjust the relative path to match your directory layout, then run `composer update`.

## Quick start

### 1. Define your SKUs

```php
use Simnuxco\SubscriptionKit\SkuConfig;

$skus = new SkuConfig([
    'monthly' => [
        'price_id'   => 'price_xxx',      // Stripe Price ID
        'mode'       => 'subscription',    // 'subscription' or 'payment'
        'is_oneoff'  => false,             // true = cancel at period end after checkout
        'trial_days' => 14,               // null for no trial
        'label'      => 'Monthly Plan',   // human-readable display name
    ],
    'yearly' => [
        'price_id'   => 'price_yyy',
        'mode'       => 'subscription',
        'is_oneoff'  => false,
        'trial_days' => null,
        'label'      => 'Annual Plan',
    ],
]);
```

### 2. Implement the persistence interfaces

At minimum, you need three implementations (see [Interfaces you implement](#interfaces-you-implement) for full method signatures):

```php
class MySubscriptionStore implements \Simnuxco\SubscriptionKit\SubscriptionStore { /* ... */ }
class MyUserStore implements \Simnuxco\SubscriptionKit\UserStore { /* ... */ }
class MyEventIdempotencyStore implements \Simnuxco\SubscriptionKit\EventIdempotencyStore { /* ... */ }
```

### 3. Wire everything up at boot

```php
use Simnuxco\SubscriptionKit\{
    StripeClient, SkuConfig,
    CheckoutService, PortalService,
    AccessGate, ExpirationBanner,
    WebhookReceiver, AdminActions
};

// Initialize Stripe SDK (pin API version for stability)
$stripe = new StripeClient($secretKey, '2025-08-27');

// Your implementations
$users  = new MyUserStore($db);
$subs   = new MySubscriptionStore($db);
$events = new MyEventIdempotencyStore($db);

// Build services
$checkout = new CheckoutService($stripe, $skus, $users);
$portal   = new PortalService($stripe, $users);
$webhook  = new WebhookReceiver($webhookSecret, $stripe, $skus, $events, $subs, $users);
$gate     = new AccessGate();
$banner   = new ExpirationBanner();

// Admin (optional)
$admin = new AdminActions($stripe, $skus, $subs, $users, $adminUserId);
// Or with audit logging:
$admin = new AdminActions($stripe, $skus, $subs, $users, $adminUserId, new MyAuditLogger($db));
```

### 4. Use in your app

```php
// Start a checkout
$redirectUrl = $checkout->createSession(
    userId:        $user->id,
    email:         $user->email,
    name:          $user->name,
    skuCode:       'monthly',
    extraMetadata: ['source' => 'pricing_page'],
    successUrl:    'https://example.com/welcome',
    cancelUrl:     'https://example.com/pricing',
);
header("Location: $redirectUrl");

// Gate access on a protected page
$context = new \Simnuxco\SubscriptionKit\GateContext(
    role:         $user->role,
    status:       $users->getStatus($user->id),
    override:     $users->getOverride($user->id),
    subscription: $subs->findByUserId($user->id),
);
$decision = $gate->decide($context); // 'allow', 'pending', 'ended', or 'no_subscription'
```

## Concepts

### Host-agnostic design

The kit never touches a database directly. Instead, it defines interfaces (`SubscriptionStore`, `UserStore`, `EventIdempotencyStore`) that your app implements using whatever persistence layer you have — MySQL, PostgreSQL, SQLite, Redis, flat files. The kit calls your implementations; you own the storage.

### StripeClient as "proof of init"

`StripeClient` initializes the Stripe SDK's global state (API key and version) in its constructor. Service classes accept it as a constructor parameter — this ensures the SDK is configured before any API call, without threading the secret key through every method.

### Subscription lifecycle

1. **Checkout** — `CheckoutService` creates a Stripe Checkout session. The user completes payment on Stripe's hosted page.
2. **Webhook** — Stripe fires `checkout.session.completed`. `WebhookReceiver` creates the local subscription record via your `SubscriptionStore`, sets user status to `active`, and (for one-off SKUs) patches `cancel_at_period_end`.
3. **Ongoing** — Subscription updates, renewals, and failures arrive as webhook events. The receiver keeps your local state in sync.
4. **Access** — On each request, `AccessGate` reads user status + subscription state to decide access.
5. **Cancellation** — Either the user self-cancels via the Customer Portal, or an admin cancels via `AdminActions`.
6. **End** — When the subscription period expires, Stripe fires `customer.subscription.deleted`. The receiver marks it ended locally.

## SKU configuration

`SkuConfig` is an immutable map of your product codes to Stripe configuration. Build it once at boot.

Each SKU entry requires these keys:

| Key | Type | Description |
|-----|------|-------------|
| `price_id` | `string` | The Stripe Price ID (e.g., `price_1Abc...`) |
| `mode` | `string` | `'subscription'` or `'payment'` |
| `is_oneoff` | `bool` | If `true`, the subscription is patched with `cancel_at_period_end` after checkout |
| `trial_days` | `?int` | Free trial length, or `null` for no trial |
| `label` | `string` | Human-readable name for display |

```php
$skus = new SkuConfig([
    'starter_monthly' => [
        'price_id'   => 'price_starter_mo',
        'mode'       => 'subscription',
        'is_oneoff'  => false,
        'trial_days' => 7,
        'label'      => 'Starter (Monthly)',
    ],
    'one_time_access' => [
        'price_id'   => 'price_onetime',
        'mode'       => 'subscription',   // NOT 'payment' — see Design Notes
        'is_oneoff'  => true,
        'trial_days' => null,
        'label'      => '30-Day Access',
    ],
]);
```

**Lookup methods:**

```php
$skus->has('starter_monthly');                  // true
$skus->priceId('starter_monthly');              // 'price_starter_mo'
$skus->mode('starter_monthly');                 // 'subscription'
$skus->isOneoff('starter_monthly');             // false
$skus->trialDays('starter_monthly');            // 7
$skus->label('starter_monthly');                // 'Starter (Monthly)'
$skus->codeForPriceId('price_starter_mo');      // 'starter_monthly'
$skus->codes();                                 // ['starter_monthly', 'one_time_access']
$skus->all();                                   // full config array
```

All lookups except `has()`, `codeForPriceId()`, `codes()`, and `all()` throw `UnknownSkuException` if the code is not found.

## Checkout

`CheckoutService::createSession()` creates a Stripe Checkout session and returns the redirect URL.

```php
$url = $checkout->createSession(
    userId:        42,
    email:         'alice@example.com',
    name:          'Alice Smith',
    skuCode:       'starter_monthly',
    extraMetadata: ['campaign' => 'launch'],
    successUrl:    'https://example.com/welcome',
    cancelUrl:     'https://example.com/pricing',
);
// Redirect the user to $url
```

The service:
- Resolves the SKU code to a Stripe Price ID via `SkuConfig`
- Gets or creates a Stripe Customer (checks `UserStore` for an existing customer ID first)
- Sets `metadata.user_id` on the Checkout session and subscription data (used by the webhook receiver to associate events with your user)
- Merges `$extraMetadata` into session and subscription metadata
- Applies `trial_period_days` if the SKU specifies one
- Selects Checkout mode (`subscription` or `payment`) from the SKU config

Checkout does **not** create the local subscription record or patch one-off subscriptions — the webhook receiver handles both.

## Customer Portal

`PortalService::createSession()` creates a Stripe Billing Portal session. Users can manage their subscription (upgrade, downgrade, cancel, update payment method) through Stripe's hosted UI.

```php
$url = $portal->createSession(
    userId:    42,
    returnUrl: 'https://example.com/account',
);
// Redirect the user to $url
```

Throws `RuntimeException` if the user has no Stripe customer ID (they need to complete checkout first).

## Webhooks

`WebhookReceiver` is the core event handler. Your webhook endpoint should:

1. Read the raw POST body and `Stripe-Signature` header
2. Begin a database transaction
3. Call `$webhook->handle($payload, $signature)`
4. Commit on success (`isSuccess()` returns true), rollback otherwise
5. Return the result's HTTP status and JSON body to Stripe

```php
// In your webhook endpoint (e.g., /stripe/webhook)
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];

$db->beginTransaction();
try {
    $result = $webhook->handle($payload, $signature);

    if ($result->isSuccess()) {
        $db->commit();
    } else {
        $db->rollBack();
    }
} catch (\Throwable $e) {
    $db->rollBack();
    $result = new \Simnuxco\SubscriptionKit\WebhookResult(500, ['error' => 'internal']);
}

http_response_code($result->httpStatus);
header('Content-Type: application/json');
echo json_encode($result->body);
```

**Why the host owns the transaction:** The idempotency-mark-then-dispatch pattern only works atomically when the event mark is in the same transaction as the side effects. The kit can't own the transaction because it doesn't know your DB driver.

### Handled events

| Stripe Event | What the receiver does |
|-------------|----------------------|
| `checkout.session.completed` | Upserts subscription, sets user status to `active`, calls optional `CheckoutHook`, patches `cancel_at_period_end` for one-off SKUs |
| `customer.subscription.created` | Upserts subscription (idempotent with checkout handler) |
| `customer.subscription.updated` | Upserts subscription with latest state |
| `customer.subscription.deleted` | Marks subscription ended via `SubscriptionStore::markEnded()` |
| `customer.subscription.trial_will_end` | Calls `SubscriptionStore::onTrialEnding()` |
| `invoice.paid` | Logged, no local action |
| `invoice.payment_failed` | Upserts subscription with `past_due` status |
| Unknown events | Returns 200 OK (prevents Stripe from retrying) |

### Duplicate detection

`WebhookReceiver` calls `EventIdempotencyStore::recordEvent()` before dispatching. If the event was already processed, it returns a `WebhookResult` with `duplicate: true` and HTTP 200 — your code can check `$result->duplicate` but should still commit (the idempotency mark itself is the side effect).

### Post-checkout hook

For host-specific work after a successful checkout (creating app-specific rows, sending welcome emails, enrolling users in onboarding), implement the optional `CheckoutHook` interface and pass it to `WebhookReceiver`:

```php
class MyCheckoutHook implements \Simnuxco\SubscriptionKit\CheckoutHook
{
    public function afterCheckoutCompleted(
        \Stripe\Checkout\Session $session,
        int $userId,
    ): void {
        // Runs inside the webhook transaction
    }
}

$webhook = new WebhookReceiver(
    $webhookSecret, $stripe, $skus, $events, $subs, $users,
    checkoutHook: new MyCheckoutHook($db),
);
```

## Access gating

`AccessGate` decides whether a user should access gated functionality. Build a `GateContext` from your user's current state, then call `decide()`.

```php
use Simnuxco\SubscriptionKit\{AccessGate, GateContext};

$gate = new AccessGate();

$context = new GateContext(
    role:         'buyer',                          // user's role in your system
    status:       $users->getStatus($userId),       // 'pending', 'active', or 'suspended'
    override:     $users->getOverride($userId),     // null, 'comp', 'admin', etc.
    subscription: $subs->findByUserId($userId),     // SubscriptionState or null
    gatedRoles:   ['buyer'],                        // which roles require a subscription
);

$decision = $gate->decide($context);
// AccessGate::ALLOW            — user has access
// AccessGate::PENDING          — user is in 'pending' status (e.g., email not confirmed)
// AccessGate::ENDED            — subscription has expired
// AccessGate::NO_SUBSCRIPTION  — user has never subscribed
```

### Decision logic (evaluated in order)

1. Override is set → `allow` (comp/admin access bypasses all checks)
2. Role is not in `gatedRoles` → `allow` (ungated roles always pass)
3. User status is `pending` → `pending`
4. No subscription exists → `no_subscription`
5. Subscription status is `active` or `trialing` → `allow`
6. Anything else → `ended`

### Gated roles

By default, only users with the `buyer` role are gated. Pass your own list if your app uses different role names:

```php
$context = new GateContext(
    role:       'subscriber',
    status:     'active',
    override:   null,
    subscription: $sub,
    gatedRoles: ['subscriber', 'premium'],  // only these roles need a subscription
);
```

Roles not in `gatedRoles` always get `allow`.

## Expiration banners

`ExpirationBanner` computes whether to show an end-of-period warning when a subscription is set to cancel at period end.

```php
use Simnuxco\SubscriptionKit\ExpirationBanner;

$banner = new ExpirationBanner();  // default trigger days: [10, 7, 4, 2, 0]

$sub    = $subs->findByUserId($userId);
$result = $banner->compute($sub);

if ($result !== null) {
    echo "Your subscription ends in {$result->daysRemaining} days.";
    // $result->severity is one of: 'info', 'warning', 'urgent', 'final'
}
```

### Trigger days

Banners only appear on specific days-remaining values — it's a deliberate nag cadence, not a continuous countdown. The default schedule is `[10, 7, 4, 2, 0]`. Customize it at construction:

```php
$banner = new ExpirationBanner(triggerDays: [30, 14, 7, 3, 1, 0]);
```

### Severity levels

| Severity | Condition |
|----------|-----------|
| `info` | More than 4 days remaining |
| `warning` | 2 to 4 days remaining |
| `urgent` | 1 day remaining |
| `final` | 0 or fewer days remaining |

### Always-on days-remaining

For UI that needs a continuous countdown (not gated to trigger days), use `daysRemaining()`:

```php
$days = $banner->daysRemaining($sub);
// Returns int when subscription has cancel_at_period_end + period_end set
// Returns null otherwise
```

## Admin actions

`AdminActions` provides admin-facing subscription management with optional audit logging.

```php
use Simnuxco\SubscriptionKit\AdminActions;

$admin = new AdminActions(
    $stripe, $skus, $subs, $users,
    adminUserId: $currentAdmin->id,
    audit:       new MyAuditLogger($db),  // optional, defaults to NullAuditLogger
);
```

### Available operations

**Sync subscription** — re-fetch from Stripe and update local state:

```php
$state = $admin->syncSubscription(
    stripeSubscriptionId: 'sub_xxx',
    userId:               42,
    localTargetId:        $localRowId,  // optional, for audit log
    note:                 'Manual sync after support ticket',
);
```

**Cancel subscription** — schedule cancellation at period end:

```php
$state = $admin->cancelSubscription(
    stripeSubscriptionId: 'sub_xxx',
    localTargetId:        $localRowId,
    note:                 'User requested via support',
);
// Mirrors cancel_at_period_end locally for immediate UI update
// Stripe fires the webhook later; the receiver upserts idempotently
```

**Set/clear access override:**

```php
$admin->setOverride(userId: 42, value: 'comp', note: 'Comp access for beta tester');
$admin->clearOverride(userId: 42, note: 'Beta period ended');
```

Authorization and self-target guards are your app's responsibility — the kit does not check whether the admin is allowed to perform the action.

## Interfaces you implement

The kit defines five interfaces. Three are required; two are optional.

### SubscriptionStore (required)

Manages local subscription records.

```php
interface SubscriptionStore
{
    public function findByCustomerId(string $stripeCustomerId): ?SubscriptionState;
    public function findByUserId(int $userId): ?SubscriptionState;
    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?SubscriptionState;
    public function upsert(SubscriptionState $state, int $userId): void;
    public function markEnded(string $stripeSubscriptionId): void;
    public function onTrialEnding(string $stripeSubscriptionId): void;
}
```

- `upsert()` should INSERT or UPDATE atomically by `stripe_subscription_id`
- `markEnded()` is called when `customer.subscription.deleted` fires
- `onTrialEnding()` is called when `customer.subscription.trial_will_end` fires — implement as a no-op if you don't use trials

### UserStore (required)

Manages user status, override markers, and Stripe customer association.

```php
interface UserStore
{
    public function getStatus(int $userId): string;    // 'pending' | 'active' | 'suspended'
    public function setStatus(int $userId, string $status): void;
    public function getOverride(int $userId): ?string;
    public function setOverride(int $userId, ?string $override): void;
    public function getStripeCustomerId(int $userId): ?string;
    public function setStripeCustomerId(int $userId, string $stripeCustomerId): void;
    public function findUserIdByEmail(string $email): ?int;
}
```

- `findUserIdByEmail()` is used by the webhook receiver to associate events with users when `metadata.user_id` is missing

### EventIdempotencyStore (required)

Deduplicates Stripe webhook events.

```php
interface EventIdempotencyStore
{
    public function recordEvent(string $eventId, string $eventType): bool;
}
```

- Return `true` if the event was newly recorded (proceed with processing)
- Return `false` if duplicate (skip processing)
- Must execute inside the same DB transaction as the webhook handler — if the handler fails and the transaction rolls back, the idempotency mark rolls back too, allowing Stripe's redelivery to succeed

### CheckoutHook (optional)

Post-checkout side effects.

```php
interface CheckoutHook
{
    public function afterCheckoutCompleted(
        \Stripe\Checkout\Session $session,
        int $userId,
    ): void;
}
```

Runs inside the webhook transaction. Use for creating app-specific rows, sending welcome emails, or enrolling users in onboarding flows.

### AuditLogger (optional)

Admin action audit trail.

```php
interface AuditLogger
{
    public function log(
        int $adminUserId,
        string $action,
        ?string $targetTable,
        ?int $targetId,
        ?array $from,
        ?array $to,
        ?string $note,
    ): void;
}
```

If not provided, `AdminActions` falls back to the built-in `NullAuditLogger` (no-op).

## API reference

### Value objects

| Class | Description |
|-------|-------------|
| `SkuConfig` | Immutable SKU map. Constructed from an associative array of code → config. |
| `SubscriptionState` | Normalized subscription snapshot. Build with `fromStripeSubscription()`, `fromArray()`, or the constructor. Readonly properties: `stripeSubscriptionId`, `stripeCustomerId`, `skuCode`, `status`, `currentPeriodStart`, `currentPeriodEnd`, `cancelAtPeriodEnd`. |
| `GateContext` | Input to `AccessGate::decide()`. Readonly properties: `role`, `status`, `override`, `subscription`, `gatedRoles`. |
| `ExpirationBannerData` | Returned by `ExpirationBanner::compute()`. Readonly properties: `daysRemaining`, `severity`. |
| `WebhookResult` | Returned by `WebhookReceiver::handle()`. Readonly properties: `httpStatus`, `body`, `duplicate`. Method: `isSuccess()`. |
| `UnknownSkuException` | Thrown by `SkuConfig` lookups. Extends `InvalidArgumentException`. |

### Services

| Class | Constructor | Key methods |
|-------|-------------|-------------|
| `StripeClient` | `(string $secretKey, ?string $apiVersion)` | (none — construction is the effect) |
| `CheckoutService` | `(StripeClient, SkuConfig, UserStore)` | `createSession(int $userId, string $email, string $name, string $skuCode, array $extraMetadata, string $successUrl, string $cancelUrl): string` |
| `PortalService` | `(StripeClient, UserStore)` | `createSession(int $userId, string $returnUrl): string` |
| `WebhookReceiver` | `(string $webhookSecret, StripeClient, SkuConfig, EventIdempotencyStore, SubscriptionStore, UserStore, ?CheckoutHook)` | `handle(string $payload, string $signatureHeader): WebhookResult` |
| `AdminActions` | `(StripeClient, SkuConfig, SubscriptionStore, UserStore, int $adminUserId, ?AuditLogger)` | `syncSubscription(...)`, `cancelSubscription(...)`, `setOverride(...)`, `clearOverride(...)` |

### Pure logic

| Class | Constructor | Key methods |
|-------|-------------|-------------|
| `AccessGate` | (none) | `decide(GateContext): string` |
| `ExpirationBanner` | `(array $triggerDays = [10, 7, 4, 2, 0])` | `compute(?SubscriptionState, ?int $nowEpoch): ?ExpirationBannerData`, `daysRemaining(?SubscriptionState, ?int $nowEpoch): ?int` |

## Testing

```bash
# With PHPUnit (requires composer install)
./vendor/bin/phpunit

# Zero-dependency fallback runner
php tests/run.php

# Single test file
php tests/run.php tests/SkuConfigTest.php
```

The fallback runner (`tests/run.php`) implements the subset of PHPUnit's API that the test suite uses, so the same test files work with or without Composer dependencies installed.

## Design notes

### One-off SKUs use subscription mode, not payment mode

A "one-off" SKU (`is_oneoff: true`) is **not** Stripe's `payment` mode. It uses `subscription` mode, and the webhook handler patches `cancel_at_period_end = true` after checkout completes. This keeps everything — Subscription objects, webhook events, period dates — on a single code path.

`mode: 'payment'` exists in `SkuConfig` for genuine one-time-charge products that don't create Subscription objects at all. The kit's services don't assume one mode over the other.

### Webhook transactionality

The one-off Stripe-update call (`cancel_at_period_end` patch) runs inside the host's webhook transaction intentionally. If the Stripe call throws, the transaction rolls back and Stripe's redelivery retries cleanly. If the Stripe call succeeds but the host's commit fails, Stripe's own idempotency prevents double-patching.

### Admin cancel mirrors locally

`AdminActions::cancelSubscription` updates your local `SubscriptionStore` immediately after patching Stripe, rather than waiting for the `customer.subscription.updated` webhook to round-trip. This ensures the admin UI reflects the change on the next page render. When the webhook arrives later, the receiver's upsert lands idempotently on the same state.

### SubscriptionState doesn't carry user_id

Stripe's Subscription object doesn't inherently know your user ID — it's only in `metadata.user_id` (which `CheckoutService` writes, but externally-created subscriptions may lack). `SubscriptionStore::upsert()` takes `$userId` as a separate parameter for this reason.

### Stripe API version compatibility

`SubscriptionState::fromStripeSubscription()` reads `current_period_start` and `current_period_end` from `items.data[0]`, not from the top-level Subscription object. This matches the Stripe API as of version 2025-08-27, which moved period fields to subscription items.

## License

MIT — see [LICENSE](LICENSE). Copyright (c) 2026 smisco.

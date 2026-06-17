<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit;

use Stripe\Webhook;
use Stripe\Event;
use Stripe\Subscription as StripeSubscription;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer as StripeCustomer;
use Stripe\Invoice;

/**
 * Stripe webhook receiver. Hosts wire a thin endpoint that:
 *
 *   1. Reads the raw POST body and the Stripe-Signature header.
 *   2. Begins a DB transaction.
 *   3. Calls handle($payload, $signature).
 *   4. Commits on success, rolls back on failure (per the returned
 *      WebhookResult's httpStatus).
 *   5. Emits the HTTP status + JSON body from the result.
 *
 * The receiver itself does NOT manage transactions — that's the host's
 * job. Reason: the package shouldn't know about the host's DB driver,
 * and the idempotency-mark-then-handler pattern only works atomically
 * when both runs in the same transaction.
 *
 * Event coverage matches SPV's pre-extraction handler:
 *   - checkout.session.completed        → upsert sub + setStatus active + optional onCheckoutHook + (one-off) cancel_at_period_end patch
 *   - customer.subscription.created     → upsert sub (idempotent)
 *   - customer.subscription.updated     → upsert sub
 *   - customer.subscription.deleted     → markEnded
 *   - customer.subscription.trial_will_end → onTrialEnding
 *   - invoice.payment_failed            → defensive past_due via upsert
 *   - invoice.paid                      → log + no-op (subscription.updated covers)
 *   - any other event type              → log + 200 OK (so Stripe stops retrying)
 */
final class WebhookReceiver
{
    public function __construct(
        private readonly string $webhookSecret,
        private readonly StripeClient $client,           // SDK init proof
        private readonly SkuConfig $skus,
        private readonly EventIdempotencyStore $events,
        private readonly SubscriptionStore $subscriptions,
        private readonly UserStore $users,
        // Application discriminator for shared Stripe accounts. Every event is
        // gated against it: an object whose metadata.app_id is not exactly this
        // value (a different app, or absent) is ignored. Required and non-empty
        // — there is no ungated mode (see SPEC decision #15). Must match the
        // appId the consumer's CheckoutService stamps.
        private readonly string $appId,
        private readonly ?CheckoutHook $checkoutHook = null,
    ) {
        if ($webhookSecret === '') {
            throw new \InvalidArgumentException('WebhookReceiver: webhookSecret is empty');
        }
        if ($appId === '') {
            throw new \InvalidArgumentException('WebhookReceiver: appId is empty');
        }
    }

    public function handle(string $payload, string $signatureHeader): WebhookResult
    {
        if ($payload === '') {
            return new WebhookResult(400, ['error' => 'empty payload']);
        }

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
        } catch (\UnexpectedValueException $e) {
            error_log('WebhookReceiver: invalid payload — ' . $e->getMessage());
            return new WebhookResult(400, ['error' => 'invalid payload']);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            error_log('WebhookReceiver: signature verification failed — ' . $e->getMessage());
            return new WebhookResult(400, ['error' => 'signature verification failed']);
        }

        // Ownership gate (shared Stripe accounts): every webhook endpoint on
        // an account receives ALL events of its subscribed types, regardless
        // of which app created the object. Ignore any event whose owning
        // object isn't tagged with our app_id — a different app_id, or none at
        // all, is "not ours" (binary; no DB fallback for absent app_id). Runs
        // BEFORE the idempotency mark so co-tenant events never enter this
        // consumer's event store. "Ignore" returns success (200) so Stripe
        // stops retrying; nothing is read, written, or mutated. (SPEC #15.)
        if ($this->isGatedType((string)$event->type)) {
            $ownerAppId = $this->resolveOwnerAppId($event);
            if ($ownerAppId !== $this->appId) {
                error_log(
                    'WebhookReceiver: ignoring foreign event ' . $event->id
                    . ' (' . $event->type . ') app_id=' . ($ownerAppId ?? 'absent')
                );
                return new WebhookResult(200, ['received' => true, 'ignored' => true], ignored: true);
            }
        }

        // Idempotency: mark the event id before doing work. If already
        // recorded, this delivery is a re-fire of one we processed —
        // skip. (Host wraps the whole handle() call in a transaction, so
        // a handler failure rolls back this mark too; Stripe redelivery
        // then runs cleanly.)
        if (!$this->events->recordEvent((string)$event->id, (string)$event->type)) {
            error_log('WebhookReceiver: duplicate event ' . $event->id . ' (' . $event->type . ') — skipped');
            return new WebhookResult(200, ['received' => true, 'duplicate' => true], duplicate: true);
        }

        try {
            $this->dispatch($event);
        } catch (\Throwable $e) {
            error_log(
                'WebhookReceiver: handler error on ' . $event->type
                . ' (event=' . $event->id . ') — ' . $e->getMessage()
            );
            return new WebhookResult(500, ['error' => 'handler error']);
        }

        return new WebhookResult(200, ['received' => true]);
    }

    private function dispatch(Event $event): void
    {
        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handleCheckoutCompleted($event->data->object);
                return;
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpsert($event->data->object);
                return;
            case 'customer.subscription.deleted':
                $this->subscriptions->markEnded((string)$event->data->object->id);
                return;
            case 'customer.subscription.trial_will_end':
                $this->subscriptions->onTrialEnding((string)$event->data->object->id);
                return;
            case 'invoice.payment_failed':
                $this->handleInvoiceFailed($event->data->object);
                return;
            case 'invoice.paid':
                error_log('WebhookReceiver: invoice.paid ' . ($event->data->object->id ?? '?'));
                return;
            default:
                // 200 OK for unknown event types so Stripe stops retrying.
                error_log('WebhookReceiver: ignoring event type ' . $event->type);
                return;
        }
    }

    /** Event types whose owning object we can attribute to an app. */
    private function isGatedType(string $type): bool
    {
        return match ($type) {
            'checkout.session.completed',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.subscription.trial_will_end',
            'invoice.paid',
            'invoice.payment_failed' => true,
            default                  => false,
        };
    }

    /**
     * The app_id of the object this event is about, or null if none can be
     * resolved. checkout.session.* and customer.subscription.* carry metadata
     * on the event object itself; invoice.* needs a little more work.
     */
    private function resolveOwnerAppId(Event $event): ?string
    {
        $obj = $event->data->object;
        if ($obj instanceof Invoice) {
            return $this->resolveInvoiceAppId($obj);
        }
        return $this->metaAppId($obj);
    }

    /** Read a non-empty metadata.app_id off any Stripe object, else null. */
    private function metaAppId(object $obj): ?string
    {
        $appId = $obj->metadata->app_id ?? null;
        return ($appId === null || $appId === '') ? null : (string)$appId;
    }

    /**
     * Resolve the owning app_id for an invoice event, cheapest source first:
     *   1. invoice.subscription_details.metadata — inline copy of the sub's
     *      metadata Stripe puts on invoices (no API call).
     *   2. invoice.metadata — the invoice's own metadata.
     *   3. retrieve the subscription and read its metadata (last resort, one
     *      Stripe API call).
     */
    private function resolveInvoiceAppId(Invoice $invoice): ?string
    {
        $inline = $invoice->subscription_details->metadata->app_id ?? null;
        if ($inline !== null && $inline !== '') {
            return (string)$inline;
        }

        $own = $invoice->metadata->app_id ?? null;
        if ($own !== null && $own !== '') {
            return (string)$own;
        }

        $sub_id = $invoice->subscription ?? null;
        if ($sub_id) {
            try {
                $sub = StripeSubscription::retrieve((string)$sub_id);
                return $this->metaAppId($sub);
            } catch (\Throwable $e) {
                error_log(
                    'WebhookReceiver: invoice app_id retrieve failed '
                    . '(invoice=' . ($invoice->id ?? '?') . ', sub=' . $sub_id . ') — ' . $e->getMessage()
                );
            }
        }
        return null;
    }

    private function handleCheckoutCompleted(CheckoutSession $session): void
    {
        $user_id  = isset($session->metadata->user_id) ? (int)$session->metadata->user_id : null;
        $sku_code = $session->metadata->sku_code ?? null;
        $sub_id   = $session->subscription ?? null;

        if (!$user_id || !$sub_id || !$sku_code) {
            error_log(
                'WebhookReceiver: checkout.session.completed missing metadata/subscription '
                . '(session=' . $session->id . ')'
            );
            return;
        }

        // Fetch the live subscription to get authoritative status, period
        // timestamps, and price (so we can verify and persist).
        $live = StripeSubscription::retrieve((string)$sub_id);
        $state = SubscriptionState::fromStripeSubscription($live, (string)$sku_code);
        $this->subscriptions->upsert($state, $user_id);

        // Host hook (SPV uses this to lazily create the buyers row).
        if ($this->checkoutHook !== null) {
            $this->checkoutHook->afterCheckoutCompleted($session, $user_id);
        }

        // Flip user.status to 'active' (was 'pending' after registration).
        // Idempotent — repeated deliveries hit the same target state.
        if ($this->users->getStatus($user_id) === 'pending') {
            $this->users->setStatus($user_id, 'active');
        }

        // One-off SKU? Patch the Stripe Subscription with cancel_at_period_end.
        // Stripe re-fires customer.subscription.updated, which mirrors the
        // flag into our SubscriptionStore via the next pass through this
        // receiver. This Stripe API call sits INSIDE the host's
        // transaction by design: if it throws, the outer dispatcher rolls
        // back our DB work and Stripe redelivery retries cleanly. If it
        // succeeds but COMMIT later fails, Stripe's own idempotency keeps
        // a redelivery from double-patching.
        if ($this->skus->has((string)$sku_code) && $this->skus->isOneoff((string)$sku_code)) {
            StripeSubscription::update((string)$sub_id, ['cancel_at_period_end' => true]);
        }
    }

    private function handleSubscriptionUpsert(StripeSubscription $sub): void
    {
        // user_id resolution, in order of preference:
        //   1. subscription.metadata.user_id (canonical write path —
        //      CheckoutService always sets this).
        //   2. local row already keyed by customer_id — use the 0
        //      sentinel since upsert hits ON CONFLICT and ignores
        //      user_id (same pattern as handleInvoiceFailed /
        //      AdminActions).
        //   3. Stripe Customer email → UserStore::findUserIdByEmail —
        //      handles subs created outside the host (Stripe Dashboard,
        //      migrations, manual API calls). One extra Stripe API call,
        //      only on this fallback.
        $user_id = isset($sub->metadata->user_id) ? (int)$sub->metadata->user_id : null;
        if (!$user_id) {
            $found = $this->subscriptions->findByCustomerId((string)$sub->customer);
            if ($found !== null) {
                $user_id = 0;
            } else {
                $resolved = null;
                try {
                    $customer = StripeCustomer::retrieve((string)$sub->customer);
                    $email = $customer->email ?? null;
                    if ($email) {
                        $resolved = $this->users->findUserIdByEmail((string)$email);
                    }
                } catch (\Throwable $e) {
                    error_log(
                        'WebhookReceiver: customer email lookup failed '
                        . '(sub=' . $sub->id . ', customer=' . $sub->customer . ') — ' . $e->getMessage()
                    );
                }
                if (!$resolved) {
                    error_log(
                        'WebhookReceiver: subscription event missing user_id; unable to resolve '
                        . '(sub=' . $sub->id . ', customer=' . $sub->customer . ')'
                    );
                    return;
                }
                $user_id = $resolved;
            }
        }

        // SKU resolution: metadata first, then price-id reverse lookup,
        // matching the webhook's pre-extraction precedence.
        $sku = null;
        $metadataCode = $sub->metadata->sku_code ?? '';
        if ($metadataCode === '') {
            $priceId = $sub->items->data[0]->price->id ?? null;
            if ($priceId !== null) {
                $sku = $this->skus->codeForPriceId($priceId);
            }
        }
        $state = SubscriptionState::fromStripeSubscription($sub, $sku);

        if ($state->skuCode === '') {
            error_log('WebhookReceiver: subscription event has no resolvable sku_code (sub=' . $sub->id . ')');
            return;
        }

        $this->subscriptions->upsert($state, $user_id);
    }

    private function handleInvoiceFailed(Invoice $invoice): void
    {
        $sub_id = $invoice->subscription ?? null;
        if (!$sub_id) return;

        // Look up the local row to preserve everything else, then upsert
        // with status=past_due. The pre-extraction code did a targeted
        // `UPDATE subscriptions SET status='past_due' WHERE
        // stripe_subscription_id = :sid` — using upsert here means we
        // also touch updated_at. Behaviorally equivalent.
        $existing = $this->subscriptions->findByStripeSubscriptionId((string)$sub_id);
        if ($existing === null) return;

        $new = new SubscriptionState(
            stripeSubscriptionId: $existing->stripeSubscriptionId,
            stripeCustomerId:     $existing->stripeCustomerId,
            skuCode:              $existing->skuCode,
            status:               'past_due',
            currentPeriodStart:   $existing->currentPeriodStart,
            currentPeriodEnd:     $existing->currentPeriodEnd,
            cancelAtPeriodEnd:    $existing->cancelAtPeriodEnd,
        );
        // user_id sentinel — the row already exists, upsert hits the
        // ON CONFLICT path which doesn't read user_id.
        $this->subscriptions->upsert($new, 0);
    }
}

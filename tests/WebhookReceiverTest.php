<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit\Tests;

use PHPUnit\Framework\TestCase;
use Smisco\SubscriptionKit\SkuConfig;
use Smisco\SubscriptionKit\StripeClient;
use Smisco\SubscriptionKit\SubscriptionState;
use Smisco\SubscriptionKit\WebhookReceiver;
use Smisco\SubscriptionKit\Tests\Support\InMemoryEventIdempotencyStore;
use Smisco\SubscriptionKit\Tests\Support\InMemorySubscriptionStore;
use Smisco\SubscriptionKit\Tests\Support\InMemoryUserStore;
use Smisco\SubscriptionKit\Tests\Support\RecordingCheckoutHook;
use Smisco\SubscriptionKit\Tests\Support\StripeHttpClientFake;
use Stripe\ApiRequestor;

final class WebhookReceiverTest extends TestCase
{
    private const SECRET = 'whsec_test_secret_for_signing';
    private const APP_ID = 'testapp';

    private StripeHttpClientFake $http;
    private InMemoryEventIdempotencyStore $events;
    private InMemorySubscriptionStore $subs;
    private InMemoryUserStore $users;
    private SkuConfig $skus;
    private StripeClient $client;
    private WebhookReceiver $receiver;
    private RecordingCheckoutHook $hook;
    private ?string $errorLogPath = null;
    private ?string $prevErrorLog = null;

    protected function setUp(): void
    {
        // Route error_log() output to a tempfile so receiver's diagnostic
        // logs don't pollute test stdout. Restored in tearDown.
        $this->errorLogPath = (string)tempnam(sys_get_temp_dir(), 'wrx');
        $this->prevErrorLog = (string)ini_get('error_log');
        ini_set('error_log', $this->errorLogPath);

        $this->http = new StripeHttpClientFake();
        ApiRequestor::setHttpClient($this->http);

        $this->events = new InMemoryEventIdempotencyStore();
        $this->subs   = new InMemorySubscriptionStore();
        $this->users  = new InMemoryUserStore();
        $this->hook   = new RecordingCheckoutHook();

        $this->skus = SkuConfig::fromArray([
            'monthly_recurring' => ['price_id' => 'price_mrec', 'is_oneoff' => false, 'label' => 'Monthly'],
            'monthly_oneoff'    => ['price_id' => 'price_mone', 'is_oneoff' => true,  'label' => 'Monthly (1x)'],
        ]);

        $this->client   = new StripeClient('sk_test_dummy', '2025-08-27.basil');
        $this->receiver = new WebhookReceiver(
            self::SECRET,
            $this->client,
            $this->skus,
            $this->events,
            $this->subs,
            $this->users,
            self::APP_ID,
            $this->hook,
        );
    }

    protected function tearDown(): void
    {
        // Restore default HTTP client so other tests aren't affected.
        ApiRequestor::setHttpClient(null);

        if ($this->prevErrorLog !== null) {
            ini_set('error_log', $this->prevErrorLog);
        }
        if ($this->errorLogPath !== null && file_exists($this->errorLogPath)) {
            @unlink($this->errorLogPath);
        }
    }

    // ---- helpers --------------------------------------------------------

    /** @return array{0:string, 1:string} payload + Stripe-Signature header */
    private function sign(array $event): array
    {
        $payload   = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signed    = $timestamp . '.' . $payload;
        $sig       = hash_hmac('sha256', $signed, self::SECRET);
        return [$payload, "t={$timestamp},v1={$sig}"];
    }

    /**
     * Top-level shallow merge so callers can fully replace nested keys
     * like 'metadata' or 'items' by setting them on the override array.
     */
    private function subscriptionObject(array $overrides = []): array
    {
        return array_replace([
            'id'                     => 'sub_test_1',
            'object'                 => 'subscription',
            'customer'               => 'cus_test_1',
            'status'                 => 'active',
            'cancel_at_period_end'   => false,
            'metadata'               => ['user_id' => '42', 'sku_code' => 'monthly_recurring', 'app_id' => self::APP_ID],
            'items'                  => ['data' => [[
                'price'                => ['id' => 'price_mrec'],
                'current_period_start' => 1700000000,
                'current_period_end'   => 1702592000,
            ]]],
        ], $overrides);
    }

    private function checkoutSessionObject(array $overrides = []): array
    {
        return array_replace([
            'id'           => 'cs_test_1',
            'object'       => 'checkout.session',
            'subscription' => 'sub_test_1',
            'metadata'     => ['user_id' => '42', 'sku_code' => 'monthly_recurring', 'app_id' => self::APP_ID],
        ], $overrides);
    }

    private function invoiceObject(array $overrides = []): array
    {
        return array_replace([
            'id'                   => 'in_test_1',
            'object'               => 'invoice',
            'subscription'         => 'sub_test_1',
            // Inline copy of the subscription's metadata Stripe puts on invoices;
            // lets the ownership gate resolve app_id without a retrieve.
            'subscription_details' => ['metadata' => ['app_id' => self::APP_ID]],
        ], $overrides);
    }

    private function envelope(string $type, array $obj, string $id = 'evt_test_1'): array
    {
        return [
            'id'      => $id,
            'object'  => 'event',
            'type'    => $type,
            'data'    => ['object' => $obj],
        ];
    }

    // ---- handle() paths -------------------------------------------------

    public function test_empty_payload_returns_400(): void
    {
        $r = $this->receiver->handle('', 'sig');
        $this->assertSame(400, $r->httpStatus);
        $this->assertSame(['error' => 'empty payload'], $r->body);
    }

    public function test_bad_signature_returns_400(): void
    {
        $r = $this->receiver->handle('{"id":"evt_x","type":"foo"}', 't=1,v1=deadbeef');
        $this->assertSame(400, $r->httpStatus);
        $this->assertSame('signature verification failed', $r->body['error'] ?? '');
    }

    public function test_duplicate_event_returns_200_with_duplicate_flag(): void
    {
        [$p, $h] = $this->sign($this->envelope('invoice.paid', $this->invoiceObject()));

        $first = $this->receiver->handle($p, $h);
        $this->assertSame(200, $first->httpStatus);
        $this->assertFalse($first->duplicate);

        // Re-sign with a fresh signature but reuse the same event id.
        [$p2, $h2] = $this->sign($this->envelope('invoice.paid', $this->invoiceObject()));
        $second = $this->receiver->handle($p2, $h2);
        $this->assertSame(200, $second->httpStatus);
        $this->assertTrue($second->duplicate);
    }

    public function test_handler_exception_returns_500(): void
    {
        // subscription.deleted dispatches to markEnded — make that throw.
        $store = new class extends InMemorySubscriptionStore {
            public function markEnded(string $stripeSubscriptionId): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $receiver = new WebhookReceiver(
            self::SECRET, $this->client, $this->skus,
            $this->events, $store, $this->users, self::APP_ID, null,
        );

        [$p, $h] = $this->sign($this->envelope('customer.subscription.deleted', $this->subscriptionObject()));
        $r = $receiver->handle($p, $h);
        $this->assertSame(500, $r->httpStatus);
    }

    public function test_unknown_event_type_returns_200_noop(): void
    {
        [$p, $h] = $this->sign($this->envelope('product.created', ['id' => 'prod_x', 'object' => 'product']));
        $r = $this->receiver->handle($p, $h);
        $this->assertSame(200, $r->httpStatus);
        $this->assertSame([], $this->subs->upserts);
        $this->assertSame([], $this->subs->ended);
    }

    // ---- checkout.session.completed ------------------------------------

    public function test_checkout_session_missing_metadata_bails(): void
    {
        // app_id present (passes the gate) but no user_id/sku_code → handler bails.
        $obj = $this->checkoutSessionObject(['metadata' => ['app_id' => self::APP_ID]]);
        [$p, $h] = $this->sign($this->envelope('checkout.session.completed', $obj));
        $r = $this->receiver->handle($p, $h);
        $this->assertSame(200, $r->httpStatus);
        $this->assertSame([], $this->subs->upserts);
        $this->assertSame([], $this->hook->calls);
    }

    public function test_checkout_session_full_path(): void
    {
        $this->users->status[42] = 'pending';

        // SDK fetches the live subscription via Subscription::retrieve.
        $this->http->queueJson('get', '/v1/subscriptions/sub_test_1', $this->subscriptionObject());

        [$p, $h] = $this->sign($this->envelope('checkout.session.completed', $this->checkoutSessionObject()));
        $r = $this->receiver->handle($p, $h);
        $this->assertSame(200, $r->httpStatus);

        $this->assertSame(1, count($this->subs->upserts));
        $this->assertSame(42, $this->subs->upserts[0]['userId']);
        $this->assertSame('sub_test_1', $this->subs->upserts[0]['state']->stripeSubscriptionId);
        $this->assertSame('monthly_recurring', $this->subs->upserts[0]['state']->skuCode);

        $this->assertSame(1, count($this->hook->calls));
        $this->assertSame(42, $this->hook->calls[0]['userId']);

        $this->assertSame('active', $this->users->status[42]);
    }

    public function test_checkout_session_oneoff_patches_subscription(): void
    {
        $this->users->status[42] = 'pending';

        $oneoffSub = $this->subscriptionObject([
            'metadata' => ['user_id' => '42', 'sku_code' => 'monthly_oneoff'],
            'items'    => ['data' => [['price' => ['id' => 'price_mone'],
                                       'current_period_start' => 1700000000,
                                       'current_period_end'   => 1702592000]]],
        ]);
        $this->http->queueJson('get',  '/v1/subscriptions/sub_test_1', $oneoffSub);
        $this->http->queueJson('post', '/v1/subscriptions/sub_test_1', $oneoffSub);

        $session = $this->checkoutSessionObject(['metadata' => ['user_id' => '42', 'sku_code' => 'monthly_oneoff', 'app_id' => self::APP_ID]]);
        [$p, $h] = $this->sign($this->envelope('checkout.session.completed', $session));

        $r = $this->receiver->handle($p, $h);
        $this->assertSame(200, $r->httpStatus);

        // The recorded POST should set cancel_at_period_end=true.
        $patch = null;
        foreach ($this->http->recorded as $req) {
            if ($req['method'] === 'post' && str_contains($req['url'], '/v1/subscriptions/sub_test_1')) {
                $patch = $req;
                break;
            }
        }
        $this->assertNotNull($patch);
        // Stripe SDK stringifies booleans to "true" / "false" before
        // they reach the HTTP client.
        $this->assertSame('true', $patch['params']['cancel_at_period_end'] ?? null);
    }

    public function test_checkout_session_active_user_status_unchanged(): void
    {
        // User is already 'active' — receiver should not call setStatus.
        $this->users->status[42] = 'active';
        $this->http->queueJson('get', '/v1/subscriptions/sub_test_1', $this->subscriptionObject());

        [$p, $h] = $this->sign($this->envelope('checkout.session.completed', $this->checkoutSessionObject()));
        $this->receiver->handle($p, $h);

        $this->assertSame([], $this->users->statusChanges);
    }

    // ---- customer.subscription.{created,updated} -----------------------

    public function test_subscription_updated_with_metadata_user_id(): void
    {
        [$p, $h] = $this->sign($this->envelope('customer.subscription.updated', $this->subscriptionObject()));
        $r = $this->receiver->handle($p, $h);
        $this->assertSame(200, $r->httpStatus);

        $this->assertSame(1, count($this->subs->upserts));
        $this->assertSame(42, $this->subs->upserts[0]['userId']);
    }

    public function test_subscription_updated_no_metadata_local_row_exists_uses_sentinel(): void
    {
        // Bail B fix: local row exists for this customer → upsert(state, 0).
        $existing = SubscriptionState::fromArray([
            'stripe_subscription_id' => 'sub_test_1',
            'stripe_customer_id'     => 'cus_test_1',
            'sku_code'               => 'monthly_recurring',
            'status'                 => 'active',
            'current_period_start'   => 1700000000,
            'current_period_end'     => 1702592000,
            'cancel_at_period_end'   => false,
        ]);
        $this->subs->seed($existing, 7);

        // app_id present (ours, passes the gate) but no user_id → exercises the
        // user_id resolution fallback downstream of the gate.
        $obj = $this->subscriptionObject(['metadata' => ['app_id' => self::APP_ID]]);
        [$p, $h] = $this->sign($this->envelope('customer.subscription.updated', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertSame(1, count($this->subs->upserts));
        $this->assertSame(0, $this->subs->upserts[0]['userId']);
        // Seeded userId is preserved (ON CONFLICT semantics).
        $this->assertSame(7, $this->subs->userIds['sub_test_1']);
    }

    public function test_subscription_updated_no_metadata_no_row_resolves_via_email(): void
    {
        // Option E fix: fetch Stripe Customer, find user_id by email.
        $this->users->bindEmail('owner@example.test', 99);
        $this->http->queueJson('get', '/v1/customers/cus_test_1', [
            'id'     => 'cus_test_1',
            'object' => 'customer',
            'email'  => 'owner@example.test',
        ]);

        // app_id present (ours, passes the gate) but no user_id → exercises the
        // user_id resolution fallback downstream of the gate.
        $obj = $this->subscriptionObject(['metadata' => ['app_id' => self::APP_ID]]);
        [$p, $h] = $this->sign($this->envelope('customer.subscription.updated', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertSame(1, count($this->subs->upserts));
        $this->assertSame(99, $this->subs->upserts[0]['userId']);
    }

    public function test_subscription_updated_no_metadata_no_row_email_unknown_bails(): void
    {
        // Customer has an email but no host user matches → bail (no upsert).
        $this->http->queueJson('get', '/v1/customers/cus_test_1', [
            'id'     => 'cus_test_1',
            'object' => 'customer',
            'email'  => 'orphan@example.test',
        ]);

        // app_id present (ours, passes the gate) but no user_id → exercises the
        // user_id resolution fallback downstream of the gate.
        $obj = $this->subscriptionObject(['metadata' => ['app_id' => self::APP_ID]]);
        [$p, $h] = $this->sign($this->envelope('customer.subscription.updated', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertSame([], $this->subs->upserts);
    }

    public function test_subscription_updated_no_metadata_no_row_customer_no_email_bails(): void
    {
        // Customer object lacks an email field entirely → bail cleanly.
        $this->http->queueJson('get', '/v1/customers/cus_test_1', [
            'id'     => 'cus_test_1',
            'object' => 'customer',
        ]);

        // app_id present (ours, passes the gate) but no user_id → exercises the
        // user_id resolution fallback downstream of the gate.
        $obj = $this->subscriptionObject(['metadata' => ['app_id' => self::APP_ID]]);
        [$p, $h] = $this->sign($this->envelope('customer.subscription.updated', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertSame([], $this->subs->upserts);
    }

    public function test_subscription_updated_resolves_sku_via_price_id(): void
    {
        // No sku_code in metadata; reverse-lookup via the price id.
        $obj = $this->subscriptionObject([
            'metadata' => ['user_id' => '42', 'app_id' => self::APP_ID],
            'items'    => ['data' => [[
                'price'                => ['id' => 'price_mrec'],
                'current_period_start' => 1700000000,
                'current_period_end'   => 1702592000,
            ]]],
        ]);
        [$p, $h] = $this->sign($this->envelope('customer.subscription.updated', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertSame(1, count($this->subs->upserts));
        $this->assertSame('monthly_recurring', $this->subs->upserts[0]['state']->skuCode);
    }

    public function test_subscription_updated_unknown_sku_bails(): void
    {
        // Price id isn't in the SkuConfig → no resolvable sku_code → bail.
        $obj = $this->subscriptionObject([
            'metadata' => ['user_id' => '42', 'app_id' => self::APP_ID],
            'items'    => ['data' => [[
                'price'                => ['id' => 'price_unknown'],
                'current_period_start' => 1700000000,
                'current_period_end'   => 1702592000,
            ]]],
        ]);
        [$p, $h] = $this->sign($this->envelope('customer.subscription.updated', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertSame([], $this->subs->upserts);
    }

    public function test_subscription_created_dispatches_to_upsert(): void
    {
        [$p, $h] = $this->sign($this->envelope('customer.subscription.created', $this->subscriptionObject()));
        $this->receiver->handle($p, $h);
        $this->assertSame(1, count($this->subs->upserts));
    }

    // ---- subscription.deleted / trial_will_end -------------------------

    public function test_subscription_deleted_calls_markEnded(): void
    {
        [$p, $h] = $this->sign($this->envelope('customer.subscription.deleted', $this->subscriptionObject()));
        $r = $this->receiver->handle($p, $h);
        $this->assertSame(200, $r->httpStatus);
        $this->assertSame(['sub_test_1'], $this->subs->ended);
    }

    public function test_subscription_trial_will_end_calls_onTrialEnding(): void
    {
        [$p, $h] = $this->sign($this->envelope('customer.subscription.trial_will_end', $this->subscriptionObject()));
        $r = $this->receiver->handle($p, $h);
        $this->assertSame(200, $r->httpStatus);
        $this->assertSame(['sub_test_1'], $this->subs->trialEnding);
    }

    // ---- invoice.payment_failed / paid ---------------------------------

    public function test_invoice_failed_with_existing_row_upserts_past_due(): void
    {
        $existing = SubscriptionState::fromArray([
            'stripe_subscription_id' => 'sub_test_1',
            'stripe_customer_id'     => 'cus_test_1',
            'sku_code'               => 'monthly_recurring',
            'status'                 => 'active',
            'current_period_start'   => 1700000000,
            'current_period_end'     => 1702592000,
            'cancel_at_period_end'   => false,
        ]);
        $this->subs->seed($existing, 42);

        [$p, $h] = $this->sign($this->envelope('invoice.payment_failed', $this->invoiceObject()));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertSame(1, count($this->subs->upserts));
        $this->assertSame('past_due', $this->subs->upserts[0]['state']->status);
        $this->assertSame(0, $this->subs->upserts[0]['userId']); // sentinel — ON CONFLICT path
    }

    public function test_invoice_failed_no_row_is_noop(): void
    {
        [$p, $h] = $this->sign($this->envelope('invoice.payment_failed', $this->invoiceObject()));
        $r = $this->receiver->handle($p, $h);
        $this->assertSame(200, $r->httpStatus);
        $this->assertSame([], $this->subs->upserts);
    }

    public function test_invoice_failed_no_subscription_id_is_noop(): void
    {
        $obj = $this->invoiceObject(['subscription' => null]);
        [$p, $h] = $this->sign($this->envelope('invoice.payment_failed', $obj));
        $r = $this->receiver->handle($p, $h);
        $this->assertSame(200, $r->httpStatus);
        $this->assertSame([], $this->subs->upserts);
    }

    public function test_invoice_paid_is_noop(): void
    {
        [$p, $h] = $this->sign($this->envelope('invoice.paid', $this->invoiceObject()));
        $r = $this->receiver->handle($p, $h);
        $this->assertSame(200, $r->httpStatus);
        $this->assertSame([], $this->subs->upserts);
        $this->assertSame([], $this->subs->ended);
    }

    // ---- app_id ownership gate -----------------------------------------

    public function test_empty_app_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebhookReceiver(
            self::SECRET, $this->client, $this->skus,
            $this->events, $this->subs, $this->users, '', $this->hook,
        );
    }

    public function test_foreign_app_id_subscription_event_ignored_and_not_recorded(): void
    {
        // A co-tenant app's subscription event — different app_id. Must be
        // ignored with no side effects, and (gate runs before idempotency)
        // must NOT be recorded in the event store.
        $obj = $this->subscriptionObject([
            'metadata' => ['user_id' => '42', 'sku_code' => 'monthly_recurring', 'app_id' => 'otherapp'],
        ]);
        [$p, $h] = $this->sign($this->envelope('customer.subscription.updated', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertTrue($r->ignored);
        $this->assertSame([], $this->subs->upserts);
        $this->assertSame([], $this->events->recorded); // gate fired before recordEvent
    }

    public function test_absent_app_id_subscription_event_ignored(): void
    {
        // Migration-gap case: an object with no app_id at all is treated as
        // not-ours (binary gate, no DB fallback for absent app_id).
        $obj = $this->subscriptionObject([
            'metadata' => ['user_id' => '42', 'sku_code' => 'monthly_recurring'],
        ]);
        [$p, $h] = $this->sign($this->envelope('customer.subscription.updated', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertTrue($r->ignored);
        $this->assertSame([], $this->subs->upserts);
        $this->assertSame([], $this->events->recorded);
    }

    public function test_matching_app_id_subscription_event_processed(): void
    {
        // Our own event (default fixture app_id == configured) processes
        // normally and is NOT flagged ignored.
        [$p, $h] = $this->sign($this->envelope('customer.subscription.updated', $this->subscriptionObject()));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertFalse($r->ignored);
        $this->assertSame(1, count($this->subs->upserts));
        $this->assertArrayHasKey('evt_test_1', $this->events->recorded);
    }

    public function test_foreign_app_id_checkout_session_ignored_no_retrieve(): void
    {
        // Foreign checkout.session.completed: gate ignores it before the
        // handler runs, so the live-subscription retrieve never happens.
        $obj = $this->checkoutSessionObject([
            'metadata' => ['user_id' => '42', 'sku_code' => 'monthly_recurring', 'app_id' => 'otherapp'],
        ]);
        [$p, $h] = $this->sign($this->envelope('checkout.session.completed', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertTrue($r->ignored);
        $this->assertSame([], $this->subs->upserts);
        $this->assertSame([], $this->hook->calls);
        // No Stripe GET (would only happen if the handler ran).
        $gets = array_values(array_filter($this->http->recorded, fn($r) => $r['method'] === 'get'));
        $this->assertSame([], $gets);
    }

    public function test_foreign_invoice_inline_metadata_ignored(): void
    {
        // Invoice carries the foreign app_id inline via subscription_details —
        // resolved without a retrieve, then ignored.
        $obj = $this->invoiceObject([
            'subscription_details' => ['metadata' => ['app_id' => 'otherapp']],
        ]);
        [$p, $h] = $this->sign($this->envelope('invoice.payment_failed', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertTrue($r->ignored);
        $this->assertSame([], $this->subs->upserts);
        $gets = array_values(array_filter($this->http->recorded, fn($r) => $r['method'] === 'get'));
        $this->assertSame([], $gets); // resolved inline, no retrieve
    }

    public function test_invoice_app_id_resolved_via_invoice_metadata_fallback(): void
    {
        // No subscription_details; fall back to the invoice's own metadata.
        $existing = SubscriptionState::fromArray([
            'stripe_subscription_id' => 'sub_test_1',
            'stripe_customer_id'     => 'cus_test_1',
            'sku_code'               => 'monthly_recurring',
            'status'                 => 'active',
            'current_period_start'   => 1700000000,
            'current_period_end'     => 1702592000,
            'cancel_at_period_end'   => false,
        ]);
        $this->subs->seed($existing, 42);

        $obj = $this->invoiceObject([
            'subscription_details' => null,
            'metadata'             => ['app_id' => self::APP_ID],
        ]);
        [$p, $h] = $this->sign($this->envelope('invoice.payment_failed', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertFalse($r->ignored);
        $this->assertSame(1, count($this->subs->upserts));
        $this->assertSame('past_due', $this->subs->upserts[0]['state']->status);
        // Resolved via invoice.metadata — no subscription retrieve needed.
        $gets = array_values(array_filter($this->http->recorded, fn($r) => $r['method'] === 'get'));
        $this->assertSame([], $gets);
    }

    public function test_invoice_app_id_resolved_via_subscription_retrieve_fallback(): void
    {
        // No inline metadata anywhere → gate retrieves the subscription and
        // reads app_id off it.
        $existing = SubscriptionState::fromArray([
            'stripe_subscription_id' => 'sub_test_1',
            'stripe_customer_id'     => 'cus_test_1',
            'sku_code'               => 'monthly_recurring',
            'status'                 => 'active',
            'current_period_start'   => 1700000000,
            'current_period_end'     => 1702592000,
            'cancel_at_period_end'   => false,
        ]);
        $this->subs->seed($existing, 42);

        $this->http->queueJson('get', '/v1/subscriptions/sub_test_1', $this->subscriptionObject());

        $obj = $this->invoiceObject(['subscription_details' => null]);
        [$p, $h] = $this->sign($this->envelope('invoice.payment_failed', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertFalse($r->ignored);
        $this->assertSame(1, count($this->subs->upserts));
        $this->assertSame('past_due', $this->subs->upserts[0]['state']->status);
        // Exactly one retrieve, from the gate.
        $gets = array_values(array_filter($this->http->recorded, fn($r) => $r['method'] === 'get'));
        $this->assertSame(1, count($gets));
        $this->assertStringContainsString('/v1/subscriptions/sub_test_1', $gets[0]['url']);
    }

    public function test_foreign_invoice_via_subscription_retrieve_ignored(): void
    {
        // Retrieve fallback resolves a foreign app_id → ignored.
        $this->http->queueJson('get', '/v1/subscriptions/sub_test_1', $this->subscriptionObject([
            'metadata' => ['user_id' => '42', 'sku_code' => 'monthly_recurring', 'app_id' => 'otherapp'],
        ]));

        $obj = $this->invoiceObject(['subscription_details' => null]);
        [$p, $h] = $this->sign($this->envelope('invoice.payment_failed', $obj));
        $r = $this->receiver->handle($p, $h);

        $this->assertSame(200, $r->httpStatus);
        $this->assertTrue($r->ignored);
        $this->assertSame([], $this->subs->upserts);
        $this->assertSame([], $this->events->recorded);
    }
}

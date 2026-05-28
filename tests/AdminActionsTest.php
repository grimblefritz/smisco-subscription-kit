<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit\Tests;

use PHPUnit\Framework\TestCase;
use Simnuxco\SubscriptionKit\AdminActions;
use Simnuxco\SubscriptionKit\SkuConfig;
use Simnuxco\SubscriptionKit\StripeClient;
use Simnuxco\SubscriptionKit\SubscriptionState;
use Simnuxco\SubscriptionKit\Tests\Support\InMemorySubscriptionStore;
use Simnuxco\SubscriptionKit\Tests\Support\InMemoryUserStore;
use Simnuxco\SubscriptionKit\Tests\Support\RecordingAuditLogger;
use Simnuxco\SubscriptionKit\Tests\Support\StripeHttpClientFake;
use Stripe\ApiRequestor;

final class AdminActionsTest extends TestCase
{
    private const ADMIN_ID = 7;

    private StripeHttpClientFake $http;
    private InMemorySubscriptionStore $subs;
    private InMemoryUserStore $users;
    private SkuConfig $skus;
    private StripeClient $client;
    private RecordingAuditLogger $audit;
    private AdminActions $admin;

    protected function setUp(): void
    {
        $this->http = new StripeHttpClientFake();
        ApiRequestor::setHttpClient($this->http);

        $this->subs  = new InMemorySubscriptionStore();
        $this->users = new InMemoryUserStore();
        $this->audit = new RecordingAuditLogger();

        $this->skus = SkuConfig::fromArray([
            'monthly_recurring' => ['price_id' => 'price_mrec', 'is_oneoff' => false, 'label' => 'Monthly'],
            'yearly_recurring'  => ['price_id' => 'price_yrec', 'is_oneoff' => false, 'label' => 'Yearly'],
        ]);

        $this->client = new StripeClient('sk_test_dummy', '2025-08-27.basil');
        $this->admin  = new AdminActions(
            $this->client, $this->skus, $this->subs, $this->users,
            self::ADMIN_ID, $this->audit,
        );
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    private function stripeSubBody(array $overrides = []): array
    {
        return array_replace([
            'id'                   => 'sub_test_1',
            'object'               => 'subscription',
            'customer'             => 'cus_test_1',
            'status'               => 'active',
            'cancel_at_period_end' => false,
            'metadata'             => ['user_id' => '42', 'sku_code' => 'monthly_recurring'],
            'items'                => ['data' => [[
                'price'                => ['id' => 'price_mrec'],
                'current_period_start' => 1700000000,
                'current_period_end'   => 1702592000,
            ]]],
        ], $overrides);
    }

    private function seedSubscription(array $overrides = [], int $userId = 42): SubscriptionState
    {
        $state = SubscriptionState::fromArray(array_replace([
            'stripe_subscription_id' => 'sub_test_1',
            'stripe_customer_id'     => 'cus_test_1',
            'sku_code'               => 'monthly_recurring',
            'status'                 => 'active',
            'current_period_start'   => 1700000000,
            'current_period_end'     => 1702592000,
            'cancel_at_period_end'   => false,
        ], $overrides));
        $this->subs->seed($state, $userId);
        return $state;
    }

    // ---- syncSubscription ----------------------------------------------

    public function test_sync_fetches_stripe_and_upserts(): void
    {
        $this->seedSubscription(['status' => 'active', 'cancel_at_period_end' => false]);
        $this->http->queueJson('get', '/v1/subscriptions/sub_test_1',
            $this->stripeSubBody(['status' => 'past_due', 'cancel_at_period_end' => true]));

        $state = $this->admin->syncSubscription('sub_test_1', 42, 1001, 'manual refresh');

        $this->assertSame('past_due', $state->status);
        $this->assertTrue($state->cancelAtPeriodEnd);

        // Upsert recorded with the user_id we passed.
        $this->assertSame(1, count($this->subs->upserts));
        $this->assertSame(42, $this->subs->upserts[0]['userId']);

        // Audit row with before/after diff.
        $this->assertSame(1, count($this->audit->rows));
        $row = $this->audit->rows[0];
        $this->assertSame(self::ADMIN_ID,        $row['adminUserId']);
        $this->assertSame('subscription.sync',   $row['action']);
        $this->assertSame('subscriptions',       $row['targetTable']);
        $this->assertSame(1001,                  $row['targetId']);
        $this->assertSame('active',              $row['from']['status']);
        $this->assertSame('past_due',            $row['to']['status']);
        $this->assertSame(0,                     $row['from']['cancel_at_period_end']);
        $this->assertSame(1,                     $row['to']['cancel_at_period_end']);
        $this->assertSame('manual refresh',      $row['note']);
    }

    public function test_sync_with_no_prior_local_row_writes_null_before(): void
    {
        $this->http->queueJson('get', '/v1/subscriptions/sub_test_1', $this->stripeSubBody());

        $this->admin->syncSubscription('sub_test_1', 42);

        $this->assertSame(1, count($this->audit->rows));
        $this->assertNull($this->audit->rows[0]['from']);
    }

    public function test_sync_resolves_sku_via_price_id_when_metadata_absent(): void
    {
        $this->seedSubscription(['sku_code' => 'monthly_recurring']);
        $this->http->queueJson('get', '/v1/subscriptions/sub_test_1',
            $this->stripeSubBody([
                'metadata' => [],
                'items'    => ['data' => [[
                    'price'                => ['id' => 'price_yrec'],
                    'current_period_start' => 1700000000,
                    'current_period_end'   => 1702592000,
                ]]],
            ]));

        $state = $this->admin->syncSubscription('sub_test_1', 42);
        $this->assertSame('yearly_recurring', $state->skuCode);
    }

    public function test_sync_preserves_local_sku_when_both_metadata_and_price_lookup_miss(): void
    {
        $this->seedSubscription(['sku_code' => 'monthly_recurring']);
        $this->http->queueJson('get', '/v1/subscriptions/sub_test_1',
            $this->stripeSubBody([
                'metadata' => [],
                'items'    => ['data' => [[
                    'price'                => ['id' => 'price_unknown'],
                    'current_period_start' => 1700000000,
                    'current_period_end'   => 1702592000,
                ]]],
            ]));

        $state = $this->admin->syncSubscription('sub_test_1', 42);
        $this->assertSame('monthly_recurring', $state->skuCode);
    }

    // ---- cancelSubscription --------------------------------------------

    public function test_cancel_with_no_local_row_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->admin->cancelSubscription('sub_missing');
    }

    public function test_cancel_patches_stripe_and_mirrors_locally(): void
    {
        $this->seedSubscription(['cancel_at_period_end' => false], 42);
        // The Stripe::update call needs a queued response (Stripe API
        // returns the subscription; the receiver ignores the response).
        $this->http->queueJson('post', '/v1/subscriptions/sub_test_1', $this->stripeSubBody());

        $after = $this->admin->cancelSubscription('sub_test_1', 1001, 'user request');

        $this->assertTrue($after->cancelAtPeriodEnd);

        // POST to Stripe carries cancel_at_period_end=true.
        $patched = false;
        foreach ($this->http->recorded as $req) {
            if ($req['method'] === 'post' && str_contains($req['url'], '/v1/subscriptions/sub_test_1')) {
                if (($req['params']['cancel_at_period_end'] ?? null) === 'true') {
                    $patched = true;
                }
            }
        }
        $this->assertTrue($patched);

        // Local upsert with sentinel 0 (ON CONFLICT path).
        $this->assertSame(1, count($this->subs->upserts));
        $this->assertSame(0, $this->subs->upserts[0]['userId']);
        $this->assertTrue($this->subs->upserts[0]['state']->cancelAtPeriodEnd);

        // userId on the row is preserved from the seed (42).
        $this->assertSame(42, $this->subs->userIds['sub_test_1']);

        // Audit row.
        $this->assertSame(1, count($this->audit->rows));
        $row = $this->audit->rows[0];
        $this->assertSame('subscription.cancel', $row['action']);
        $this->assertSame(1001,                  $row['targetId']);
        $this->assertSame(0, $row['from']['cancel_at_period_end']);
        $this->assertSame(1, $row['to']['cancel_at_period_end']);
        $this->assertSame('user request', $row['note']);
    }

    // ---- setOverride / clearOverride -----------------------------------

    public function test_set_override_persists_and_audits(): void
    {
        $this->users->setOverride(99, null);

        $this->admin->setOverride(99, 'comp', 'free for life');

        $this->assertSame('comp', $this->users->getOverride(99));
        $this->assertSame(1, count($this->audit->rows));
        $row = $this->audit->rows[0];
        $this->assertSame('user.set_override', $row['action']);
        $this->assertSame('users',             $row['targetTable']);
        $this->assertSame(99,                  $row['targetId']);
        $this->assertNull($row['from']['subscription_override']);
        $this->assertSame('comp', $row['to']['subscription_override']);
        $this->assertSame('free for life', $row['note']);
    }

    public function test_set_override_captures_existing_value_as_before(): void
    {
        $this->users->setOverride(99, 'comp');

        $this->admin->setOverride(99, 'admin');

        $row = $this->audit->rows[0];
        $this->assertSame('comp',  $row['from']['subscription_override']);
        $this->assertSame('admin', $row['to']['subscription_override']);
    }

    public function test_clear_override_persists_and_audits(): void
    {
        $this->users->setOverride(99, 'comp');

        $this->admin->clearOverride(99, 'mistake');

        $this->assertNull($this->users->getOverride(99));
        $this->assertSame(1, count($this->audit->rows));
        $row = $this->audit->rows[0];
        $this->assertSame('user.clear_override', $row['action']);
        $this->assertSame(99,                    $row['targetId']);
        $this->assertSame('comp', $row['from']['subscription_override']);
        $this->assertNull($row['to']['subscription_override']);
        $this->assertSame('mistake', $row['note']);
    }

    // ---- NullAuditLogger fallback --------------------------------------

    public function test_no_audit_logger_supplied_uses_null_logger(): void
    {
        $admin = new AdminActions(
            $this->client, $this->skus, $this->subs, $this->users,
            self::ADMIN_ID,
            // no audit logger supplied
        );

        $this->users->setOverride(99, null);
        // Must not throw — NullAuditLogger silently swallows the call.
        $admin->setOverride(99, 'comp');
        $this->assertSame('comp', $this->users->getOverride(99));
    }
}

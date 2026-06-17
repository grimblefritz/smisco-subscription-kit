<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit\Tests;

use PHPUnit\Framework\TestCase;
use Smisco\SubscriptionKit\AccessGate;
use Smisco\SubscriptionKit\GateContext;
use Smisco\SubscriptionKit\SubscriptionState;

final class AccessGateTest extends TestCase
{
    private function activeSub(string $status = 'active'): SubscriptionState
    {
        return new SubscriptionState(
            'sub_abc', 'cus_xyz', 'monthly_recurring',
            $status, 1700000000, 1702592000, false
        );
    }

    public function test_override_short_circuits_to_allow(): void
    {
        $ctx = new GateContext('buyer', 'active', 'comp', null);
        $this->assertSame(AccessGate::ALLOW, (new AccessGate())->decide($ctx));
    }

    public function test_admin_override_short_circuits_to_allow(): void
    {
        $ctx = new GateContext('buyer', 'pending', 'admin', null);
        $this->assertSame(AccessGate::ALLOW, (new AccessGate())->decide($ctx));
    }

    public function test_non_gated_role_allowed(): void
    {
        $ctx = new GateContext('seller', 'active', null, null);
        $this->assertSame(AccessGate::ALLOW, (new AccessGate())->decide($ctx));
    }

    public function test_admin_role_allowed(): void
    {
        $ctx = new GateContext('admin', 'active', null, null);
        $this->assertSame(AccessGate::ALLOW, (new AccessGate())->decide($ctx));
    }

    public function test_pending_status_for_gated_role(): void
    {
        $ctx = new GateContext('buyer', 'pending', null, null);
        $this->assertSame(AccessGate::PENDING, (new AccessGate())->decide($ctx));
    }

    public function test_no_subscription(): void
    {
        $ctx = new GateContext('buyer', 'active', null, null);
        $this->assertSame(AccessGate::NO_SUBSCRIPTION, (new AccessGate())->decide($ctx));
    }

    public function test_active_subscription_allowed(): void
    {
        $ctx = new GateContext('buyer', 'active', null, $this->activeSub('active'));
        $this->assertSame(AccessGate::ALLOW, (new AccessGate())->decide($ctx));
    }

    public function test_trialing_subscription_allowed(): void
    {
        $ctx = new GateContext('buyer', 'active', null, $this->activeSub('trialing'));
        $this->assertSame(AccessGate::ALLOW, (new AccessGate())->decide($ctx));
    }

    public function test_past_due_subscription_ended(): void
    {
        $ctx = new GateContext('buyer', 'active', null, $this->activeSub('past_due'));
        $this->assertSame(AccessGate::ENDED, (new AccessGate())->decide($ctx));
    }

    public function test_canceled_subscription_ended(): void
    {
        $ctx = new GateContext('buyer', 'active', null, $this->activeSub('canceled'));
        $this->assertSame(AccessGate::ENDED, (new AccessGate())->decide($ctx));
    }

    public function test_unpaid_subscription_ended(): void
    {
        $ctx = new GateContext('buyer', 'active', null, $this->activeSub('unpaid'));
        $this->assertSame(AccessGate::ENDED, (new AccessGate())->decide($ctx));
    }

    public function test_custom_gated_roles_apply_to_named_role_only(): void
    {
        $gate = new AccessGate();

        // App with 'tenant' as the gated role
        $ctx_tenant = new GateContext('tenant', 'active', null, null, ['tenant']);
        $this->assertSame(AccessGate::NO_SUBSCRIPTION, $gate->decide($ctx_tenant));

        // Buyer in that same app is NOT gated → allowed
        $ctx_buyer = new GateContext('buyer', 'active', null, null, ['tenant']);
        $this->assertSame(AccessGate::ALLOW, $gate->decide($ctx_buyer));
    }

    public function test_multiple_gated_roles(): void
    {
        $gate = new AccessGate();
        $ctx_a = new GateContext('role_a', 'active', null, null, ['role_a', 'role_b']);
        $ctx_b = new GateContext('role_b', 'active', null, null, ['role_a', 'role_b']);
        $ctx_c = new GateContext('role_c', 'active', null, null, ['role_a', 'role_b']);
        $this->assertSame(AccessGate::NO_SUBSCRIPTION, $gate->decide($ctx_a));
        $this->assertSame(AccessGate::NO_SUBSCRIPTION, $gate->decide($ctx_b));
        $this->assertSame(AccessGate::ALLOW,           $gate->decide($ctx_c));
    }

    public function test_empty_string_override_is_treated_as_no_override(): void
    {
        // SPV's DB column is nullable; an empty string is the same as null
        // for gating purposes.
        $ctx = new GateContext('buyer', 'active', '', null);
        $this->assertSame(AccessGate::NO_SUBSCRIPTION, (new AccessGate())->decide($ctx));
    }
}

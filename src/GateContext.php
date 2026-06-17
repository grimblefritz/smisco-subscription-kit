<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit;

/**
 * Input to AccessGate::decide(). Hosts assemble this from their User row
 * + current SubscriptionState (or null when the user has no subscription).
 *
 * `gatedRoles` is the list of roles that AccessGate enforces against.
 * Anything else short-circuits to 'allow' regardless of subscription
 * state. SPV uses ['buyer']; other apps can configure their own.
 */
final class GateContext
{
    /**
     * @param string                 $role          User role: 'buyer'|'seller'|'admin'|etc.
     * @param string                 $status        Account status: 'pending'|'active'|'suspended'
     * @param ?string                $override      Bypass marker: 'comp'|'admin'|null
     * @param ?SubscriptionState     $subscription  Current subscription (null if none)
     * @param list<string>           $gatedRoles    Roles subject to gating (default ['buyer'])
     */
    public function __construct(
        public readonly string $role,
        public readonly string $status,
        public readonly ?string $override,
        public readonly ?SubscriptionState $subscription,
        public readonly array $gatedRoles = ['buyer'],
    ) {
    }
}

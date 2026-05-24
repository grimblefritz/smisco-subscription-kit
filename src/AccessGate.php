<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit;

/**
 * Decides whether a user has access to gated functionality.
 *
 * Order of evaluation:
 *   1. override set         → 'allow' (comp / admin bypass)
 *   2. role not gated       → 'allow' (e.g. sellers + admins in SPV)
 *   3. account status='pending' → 'pending' (host should route to Checkout)
 *   4. no subscription      → 'no_subscription'
 *   5. status active/trialing → 'allow'
 *   6. anything else        → 'ended' (canceled, past_due, etc.)
 *
 * Mirrors SPV's pre-extraction Auth::checkSubscriptionAccess. Stateless.
 */
final class AccessGate
{
    public const ALLOW           = 'allow';
    public const PENDING         = 'pending';
    public const ENDED           = 'ended';
    public const NO_SUBSCRIPTION = 'no_subscription';

    public function decide(GateContext $context): string
    {
        if ($context->override !== null && $context->override !== '') {
            return self::ALLOW;
        }
        if (!in_array($context->role, $context->gatedRoles, true)) {
            return self::ALLOW;
        }
        if ($context->status === 'pending') {
            return self::PENDING;
        }
        if ($context->subscription === null) {
            return self::NO_SUBSCRIPTION;
        }
        if (in_array($context->subscription->status, ['active', 'trialing'], true)) {
            return self::ALLOW;
        }
        return self::ENDED;
    }
}

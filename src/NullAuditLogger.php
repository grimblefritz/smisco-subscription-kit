<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit;

/**
 * No-op AuditLogger. AdminActions accepts a nullable AuditLogger and
 * defaults to this when none is supplied; hosts can also pass an explicit
 * instance to signal "I don't want auditing" without sprinkling null
 * checks at the call sites.
 */
final class NullAuditLogger implements AuditLogger
{
    public function log(
        int $adminUserId,
        string $action,
        ?string $targetTable,
        ?int $targetId,
        ?array $from,
        ?array $to,
        ?string $note
    ): void {
        // intentionally empty
    }
}

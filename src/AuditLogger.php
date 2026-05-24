<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit;

/**
 * Optional audit-trail hook. AdminActions writes one row per mutation via
 * this interface; hosts that already have an admin_audit_log table wire
 * a thin adapter and pass it in. Hosts that don't can use NullAuditLogger.
 *
 * The shape mirrors SPV's adminAuditLog(): admin who did it, action name
 * ('subscription.sync', 'user.set_override', etc.), target row reference,
 * before/after snapshots, and an operator note.
 *
 * Method shape:
 *   - $targetTable / $targetId may be null for actions that don't operate
 *     on a single row (a system-wide sweep, for instance).
 *   - $from / $to are JSON-encoded by the impl. Pass associative arrays.
 *   - $note is the high-stakes-action note, or null.
 *
 * The interface deliberately matches SPV's adminAuditLog parameter order
 * so SPV's adapter is a one-liner.
 */
interface AuditLogger
{
    public function log(
        int $adminUserId,
        string $action,
        ?string $targetTable,
        ?int $targetId,
        ?array $from,
        ?array $to,
        ?string $note
    ): void;
}

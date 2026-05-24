<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit;

/**
 * Host-implemented idempotency table for Stripe webhook events. Stripe
 * retries failed deliveries for up to ~3 days with exponential backoff,
 * and may also re-deliver successful ones — every consumer needs an
 * idempotency guard to keep handler side-effects from running twice.
 *
 * The convention (mirrored from SPV) is a table keyed on Stripe's
 * `event.id` (UUIDs like `evt_…`). The impl's recordEvent inserts the
 * id with `INSERT OR IGNORE`-style semantics and returns true only when
 * the row was newly created.
 *
 * Atomicity: the recordEvent call should run INSIDE the same transaction
 * that brackets the WebhookReceiver's handler work, so a handler failure
 * rolls back the idempotency mark too — Stripe redelivery then runs
 * cleanly from scratch. The host bracket-with-BEGIN/COMMIT pattern is
 * documented in the WebhookReceiver class.
 */
interface EventIdempotencyStore
{
    /**
     * Record the event id (and its type for log/diagnostic value).
     * Returns true if this is a freshly-recorded event (proceed with
     * handler dispatch), false if the id was already present (duplicate;
     * skip dispatch).
     */
    public function recordEvent(string $eventId, string $eventType): bool;
}

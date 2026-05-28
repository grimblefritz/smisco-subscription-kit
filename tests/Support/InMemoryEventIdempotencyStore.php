<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit\Tests\Support;

use Simnuxco\SubscriptionKit\EventIdempotencyStore;

final class InMemoryEventIdempotencyStore implements EventIdempotencyStore
{
    /** @var array<string, string> eventId => eventType */
    public array $recorded = [];

    public function recordEvent(string $eventId, string $eventType): bool
    {
        if (isset($this->recorded[$eventId])) {
            return false;
        }
        $this->recorded[$eventId] = $eventType;
        return true;
    }
}

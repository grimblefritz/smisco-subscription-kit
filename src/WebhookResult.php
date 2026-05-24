<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit;

/**
 * Return value from WebhookReceiver::handle. Host turns this into the
 * actual HTTP response.
 *
 * httpStatus = 200 means the receiver did its work successfully — host
 * should COMMIT its outer transaction. Anything else (4xx for invalid
 * payload / bad signature, 5xx for handler errors) is a host-should-
 * ROLLBACK signal.
 *
 * `duplicate` is true when the receiver short-circuited on the
 * idempotency check. The host can treat this as a successful no-op
 * (commit-or-rollback is equivalent because nothing was written).
 */
final class WebhookResult
{
    public function __construct(
        public readonly int $httpStatus,
        /** @var array<string,mixed> */
        public readonly array $body,
        public readonly bool $duplicate = false,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }
}

<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit\Tests\Support;

use Smisco\SubscriptionKit\UserStore;

final class InMemoryUserStore implements UserStore
{
    /** @var array<int, string> */
    public array $status = [];

    /** @var array<int, ?string> */
    public array $override = [];

    /** @var array<int, ?string> */
    public array $stripeCustomerId = [];

    /** @var array<string, int> normalized email => userId */
    public array $byEmail = [];

    /** @var list<array{userId:int, status:string}> setStatus call log */
    public array $statusChanges = [];

    public function getStatus(int $userId): string
    {
        return $this->status[$userId] ?? 'active';
    }

    public function setStatus(int $userId, string $status): void
    {
        $this->statusChanges[] = ['userId' => $userId, 'status' => $status];
        $this->status[$userId] = $status;
    }

    public function getOverride(int $userId): ?string
    {
        return $this->override[$userId] ?? null;
    }

    public function setOverride(int $userId, ?string $override): void
    {
        $this->override[$userId] = $override;
    }

    public function getStripeCustomerId(int $userId): ?string
    {
        return $this->stripeCustomerId[$userId] ?? null;
    }

    public function setStripeCustomerId(int $userId, string $stripeCustomerId): void
    {
        $this->stripeCustomerId[$userId] = $stripeCustomerId;
    }

    public function findUserIdByEmail(string $email): ?int
    {
        return $this->byEmail[strtolower(trim($email))] ?? null;
    }

    /** Test helper: bind an email → userId mapping. */
    public function bindEmail(string $email, int $userId): void
    {
        $this->byEmail[strtolower(trim($email))] = $userId;
    }
}

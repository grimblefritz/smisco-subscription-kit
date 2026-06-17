<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit\Tests\Support;

use Smisco\SubscriptionKit\SubscriptionState;
use Smisco\SubscriptionKit\SubscriptionStore;

class InMemorySubscriptionStore implements SubscriptionStore
{
    /** @var array<string, SubscriptionState> keyed by stripeSubscriptionId */
    public array $rows = [];

    /** @var array<string, int> stripeSubscriptionId => userId (last upsert) */
    public array $userIds = [];

    /** @var list<string> sub ids passed to markEnded */
    public array $ended = [];

    /** @var list<string> sub ids passed to onTrialEnding */
    public array $trialEnding = [];

    /** @var list<array{state: SubscriptionState, userId: int}> all upsert calls in order */
    public array $upserts = [];

    public function findByCustomerId(string $stripeCustomerId): ?SubscriptionState
    {
        foreach ($this->rows as $state) {
            if ($state->stripeCustomerId === $stripeCustomerId) {
                return $state;
            }
        }
        return null;
    }

    public function findByUserId(int $userId): ?SubscriptionState
    {
        foreach ($this->rows as $sid => $state) {
            if (($this->userIds[$sid] ?? 0) === $userId && $userId !== 0) {
                return $state;
            }
        }
        return null;
    }

    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?SubscriptionState
    {
        return $this->rows[$stripeSubscriptionId] ?? null;
    }

    public function upsert(SubscriptionState $state, int $userId): void
    {
        $this->upserts[] = ['state' => $state, 'userId' => $userId];
        $sid = $state->stripeSubscriptionId;
        $this->rows[$sid] = $state;
        // Mirror real impls: ignore userId=0 when the row already exists
        // (ON CONFLICT path); otherwise remember it.
        if ($userId !== 0 || !isset($this->userIds[$sid])) {
            $this->userIds[$sid] = $userId;
        }
    }

    public function markEnded(string $stripeSubscriptionId): void
    {
        $this->ended[] = $stripeSubscriptionId;
    }

    public function onTrialEnding(string $stripeSubscriptionId): void
    {
        $this->trialEnding[] = $stripeSubscriptionId;
    }

    /** Test helper: seed a row without recording it as an upsert. */
    public function seed(SubscriptionState $state, int $userId): void
    {
        $this->rows[$state->stripeSubscriptionId] = $state;
        $this->userIds[$state->stripeSubscriptionId] = $userId;
    }
}

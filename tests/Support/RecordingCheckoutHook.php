<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit\Tests\Support;

use Simnuxco\SubscriptionKit\CheckoutHook;
use Stripe\Checkout\Session as CheckoutSession;

final class RecordingCheckoutHook implements CheckoutHook
{
    /** @var list<array{sessionId:string, userId:int}> */
    public array $calls = [];

    public function afterCheckoutCompleted(CheckoutSession $session, int $userId): void
    {
        $this->calls[] = ['sessionId' => (string)$session->id, 'userId' => $userId];
    }
}

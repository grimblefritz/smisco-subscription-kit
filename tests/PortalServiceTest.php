<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit\Tests;

use PHPUnit\Framework\TestCase;
use Simnuxco\SubscriptionKit\PortalService;
use Simnuxco\SubscriptionKit\StripeClient;
use Simnuxco\SubscriptionKit\Tests\Support\InMemoryUserStore;
use Simnuxco\SubscriptionKit\Tests\Support\StripeHttpClientFake;
use Stripe\ApiRequestor;

final class PortalServiceTest extends TestCase
{
    private StripeHttpClientFake $http;
    private InMemoryUserStore $users;
    private StripeClient $client;
    private PortalService $portal;

    protected function setUp(): void
    {
        $this->http = new StripeHttpClientFake();
        ApiRequestor::setHttpClient($this->http);

        $this->users  = new InMemoryUserStore();
        $this->client = new StripeClient('sk_test_dummy', '2025-08-27.basil');
        $this->portal = new PortalService($this->client, $this->users);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function test_missing_customer_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->portal->createSession(42, 'https://return.test');
    }

    public function test_empty_string_customer_treated_as_missing(): void
    {
        $this->users->setStripeCustomerId(42, '');
        $this->expectException(\RuntimeException::class);
        $this->portal->createSession(42, 'https://return.test');
    }

    public function test_existing_customer_creates_portal_session(): void
    {
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->http->queueJson('post', '/v1/billing_portal/sessions', [
            'id'     => 'bps_test_1',
            'object' => 'billing_portal.session',
            'url'    => 'https://billing.stripe.test/p/session/x',
        ]);

        $url = $this->portal->createSession(42, 'https://app.test/profile');

        $this->assertSame('https://billing.stripe.test/p/session/x', $url);

        $req = $this->http->recorded[0] ?? null;
        $this->assertNotNull($req);
        $this->assertSame('post', $req['method']);
        $this->assertTrue(str_contains((string)$req['url'], '/v1/billing_portal/sessions'));
        $this->assertSame('cus_existing',           $req['params']['customer']   ?? null);
        $this->assertSame('https://app.test/profile', $req['params']['return_url'] ?? null);
    }
}

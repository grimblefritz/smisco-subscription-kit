<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit\Tests;

use PHPUnit\Framework\TestCase;
use Smisco\SubscriptionKit\CheckoutService;
use Smisco\SubscriptionKit\SkuConfig;
use Smisco\SubscriptionKit\StripeClient;
use Smisco\SubscriptionKit\UnknownSkuException;
use Smisco\SubscriptionKit\Tests\Support\InMemoryUserStore;
use Smisco\SubscriptionKit\Tests\Support\StripeHttpClientFake;
use Stripe\ApiRequestor;

final class CheckoutServiceTest extends TestCase
{
    private const APP_ID = 'testapp';

    private StripeHttpClientFake $http;
    private InMemoryUserStore $users;
    private SkuConfig $skus;
    private StripeClient $client;
    private CheckoutService $checkout;
    private ?string $errorLogPath = null;
    private ?string $prevErrorLog = null;

    protected function setUp(): void
    {
        $this->errorLogPath = (string)tempnam(sys_get_temp_dir(), 'cks');
        $this->prevErrorLog = (string)ini_get('error_log');
        ini_set('error_log', $this->errorLogPath);

        $this->http = new StripeHttpClientFake();
        ApiRequestor::setHttpClient($this->http);

        $this->users = new InMemoryUserStore();

        $this->skus = SkuConfig::fromArray([
            'monthly_recurring' => ['price_id' => 'price_mrec', 'is_oneoff' => false, 'label' => 'Monthly'],
            'monthly_oneoff'    => ['price_id' => 'price_mone', 'is_oneoff' => true,  'label' => 'Monthly (1x)'],
            'trial_sku'         => ['price_id' => 'price_trial', 'is_oneoff' => false, 'trial_days' => 14, 'label' => 'Trial'],
            'one_time'          => ['price_id' => 'price_one',  'is_oneoff' => false, 'mode' => 'payment', 'label' => 'One-time'],
        ]);

        $this->client   = new StripeClient('sk_test_dummy', '2025-08-27.basil');
        $this->checkout = new CheckoutService($this->client, $this->skus, $this->users, self::APP_ID);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        if ($this->prevErrorLog !== null) {
            ini_set('error_log', $this->prevErrorLog);
        }
        if ($this->errorLogPath !== null && file_exists($this->errorLogPath)) {
            @unlink($this->errorLogPath);
        }
    }

    /** Locate the first recorded request matching method + URL substring. */
    private function findRequest(string $method, string $urlSubstr): ?array
    {
        $method = strtolower($method);
        foreach ($this->http->recorded as $req) {
            if ($req['method'] === $method && str_contains($req['url'], $urlSubstr)) {
                return $req;
            }
        }
        return null;
    }

    private function queueCheckoutSession(string $url = 'https://checkout.stripe.test/cs_1'): void
    {
        $this->http->queueJson('post', '/v1/checkout/sessions', [
            'id'     => 'cs_test_1',
            'object' => 'checkout.session',
            'url'    => $url,
        ]);
    }

    private function queueCustomerCreate(string $cusId = 'cus_new_1'): void
    {
        $this->http->queueJson('post', '/v1/customers', [
            'id'     => $cusId,
            'object' => 'customer',
        ]);
    }

    // --------------------------------------------------------------------

    public function test_unknown_sku_throws(): void
    {
        $this->expectException(UnknownSkuException::class);
        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'bogus_sku', [], 'https://ok', 'https://x',
        );
    }

    public function test_returns_session_url(): void
    {
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession('https://checkout.stripe.test/CS_REDIRECT');

        $url = $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'monthly_recurring', [], 'https://ok', 'https://x',
        );

        $this->assertSame('https://checkout.stripe.test/CS_REDIRECT', $url);
    }

    public function test_existing_customer_reused_no_customer_create(): void
    {
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'monthly_recurring', [], 'https://ok', 'https://x',
        );

        // No POST to /v1/customers should have been made.
        $this->assertNull($this->findRequest('post', '/v1/customers'));

        // Checkout request used the existing customer id.
        $session = $this->findRequest('post', '/v1/checkout/sessions');
        $this->assertNotNull($session);
        $this->assertSame('cus_existing', $session['params']['customer'] ?? null);
    }

    public function test_missing_customer_created_and_persisted(): void
    {
        $this->queueCustomerCreate('cus_freshly_made');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'alice@example.test', 'Alice Cooper', 'monthly_recurring', [],
            'https://ok', 'https://x',
        );

        $create = $this->findRequest('post', '/v1/customers');
        $this->assertNotNull($create);
        $this->assertSame('alice@example.test', $create['params']['email'] ?? null);
        $this->assertSame('Alice Cooper',       $create['params']['name']  ?? null);
        $this->assertSame('42', $create['params']['metadata']['user_id'] ?? null);

        // Persisted into UserStore.
        $this->assertSame('cus_freshly_made', $this->users->getStripeCustomerId(42));

        // Checkout used the freshly-created id.
        $session = $this->findRequest('post', '/v1/checkout/sessions');
        $this->assertSame('cus_freshly_made', $session['params']['customer'] ?? null);
    }

    public function test_empty_name_passes_null_to_customer_create(): void
    {
        $this->queueCustomerCreate();
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', '   ', 'monthly_recurring', [], 'https://ok', 'https://x',
        );

        $create = $this->findRequest('post', '/v1/customers');
        // The SDK omits null params or stringifies them; either way the
        // value should not be the literal whitespace input.
        $sentName = $create['params']['name'] ?? null;
        $this->assertTrue($sentName === null || $sentName === '');
    }

    public function test_subscription_mode_includes_subscription_data_with_metadata(): void
    {
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'monthly_recurring',
            ['promo' => 'welcome2024'],
            'https://ok', 'https://x',
        );

        $session = $this->findRequest('post', '/v1/checkout/sessions');
        $this->assertSame('subscription', $session['params']['mode'] ?? null);

        $subMeta = $session['params']['subscription_data']['metadata'] ?? null;
        $this->assertNotNull($subMeta);
        $this->assertSame('42',                $subMeta['user_id']  ?? null);
        $this->assertSame('monthly_recurring', $subMeta['sku_code'] ?? null);
        $this->assertSame('welcome2024',       $subMeta['promo']    ?? null);

        // Top-level session.metadata mirrors the same.
        $topMeta = $session['params']['metadata'] ?? null;
        $this->assertSame('42',                $topMeta['user_id']  ?? null);
        $this->assertSame('monthly_recurring', $topMeta['sku_code'] ?? null);
        $this->assertSame('welcome2024',       $topMeta['promo']    ?? null);
    }

    public function test_payment_mode_omits_subscription_data(): void
    {
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'one_time', [], 'https://ok', 'https://x',
        );

        $session = $this->findRequest('post', '/v1/checkout/sessions');
        $this->assertSame('payment', $session['params']['mode'] ?? null);
        $this->assertNull($session['params']['subscription_data'] ?? null);
    }

    public function test_trial_days_injected_into_subscription_data(): void
    {
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'trial_sku', [], 'https://ok', 'https://x',
        );

        $session = $this->findRequest('post', '/v1/checkout/sessions');
        $trial = $session['params']['subscription_data']['trial_period_days'] ?? null;
        // SDK stringifies the int when form-encoding.
        $this->assertSame('14', (string)$trial);
    }

    public function test_no_trial_days_omits_trial_period_days(): void
    {
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'monthly_recurring', [], 'https://ok', 'https://x',
        );

        $session = $this->findRequest('post', '/v1/checkout/sessions');
        $this->assertNull($session['params']['subscription_data']['trial_period_days'] ?? null);
    }

    public function test_line_items_carry_price_and_quantity(): void
    {
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'monthly_oneoff', [], 'https://ok', 'https://x',
        );

        $session = $this->findRequest('post', '/v1/checkout/sessions');
        $lineItem = $session['params']['line_items'][0] ?? null;
        $this->assertNotNull($lineItem);
        $this->assertSame('price_mone', $lineItem['price'] ?? null);
        $this->assertSame('1', (string)($lineItem['quantity'] ?? ''));
    }

    public function test_success_and_cancel_urls_passed_through(): void
    {
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'monthly_recurring', [],
            'https://app.test/ok?s={CHECKOUT_SESSION_ID}',
            'https://app.test/cancel',
        );

        $session = $this->findRequest('post', '/v1/checkout/sessions');
        $this->assertSame('https://app.test/ok?s={CHECKOUT_SESSION_ID}', $session['params']['success_url'] ?? null);
        $this->assertSame('https://app.test/cancel',                     $session['params']['cancel_url']  ?? null);
    }

    // ---- app_id stamping ------------------------------------------------

    public function test_app_id_stamped_into_session_and_subscription_metadata(): void
    {
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'monthly_recurring', [], 'https://ok', 'https://x',
        );

        $session = $this->findRequest('post', '/v1/checkout/sessions');
        // Stamped on the session itself...
        $this->assertSame(self::APP_ID, $session['params']['metadata']['app_id'] ?? null);
        // ...and propagated to subscription_data so it lands on the Subscription
        // (and therefore on every subscription/invoice event it later fires).
        $this->assertSame(self::APP_ID, $session['params']['subscription_data']['metadata']['app_id'] ?? null);
    }

    public function test_app_id_stamped_into_payment_mode_session_metadata(): void
    {
        // payment mode has no subscription_data; app_id still tags the session.
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'one_time', [], 'https://ok', 'https://x',
        );

        $session = $this->findRequest('post', '/v1/checkout/sessions');
        $this->assertSame('payment', $session['params']['mode'] ?? null);
        $this->assertSame(self::APP_ID, $session['params']['metadata']['app_id'] ?? null);
        $this->assertNull($session['params']['subscription_data'] ?? null);
    }

    public function test_app_id_overrides_host_supplied_metadata_collision(): void
    {
        // app_id is identity — a host that puts 'app_id' in extraMetadata must
        // not be able to spoof it; the configured value wins.
        $this->users->setStripeCustomerId(42, 'cus_existing');
        $this->queueCheckoutSession();

        $this->checkout->createSession(
            42, 'a@b.test', 'Alice', 'monthly_recurring',
            ['app_id' => 'spoofed'],
            'https://ok', 'https://x',
        );

        $session = $this->findRequest('post', '/v1/checkout/sessions');
        $this->assertSame(self::APP_ID, $session['params']['metadata']['app_id'] ?? null);
        $this->assertSame(self::APP_ID, $session['params']['subscription_data']['metadata']['app_id'] ?? null);
    }

    public function test_empty_app_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CheckoutService($this->client, $this->skus, $this->users, '');
    }
}

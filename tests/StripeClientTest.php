<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit\Tests;

use PHPUnit\Framework\TestCase;
use Smisco\SubscriptionKit\StripeClient;
use Stripe\Stripe;

final class StripeClientTest extends TestCase
{
    public function test_empty_secret_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StripeClient('');
    }

    public function test_valid_key_sets_stripe_api_key(): void
    {
        new StripeClient('sk_test_unit_marker_42');
        $this->assertSame('sk_test_unit_marker_42', Stripe::getApiKey());
    }

    public function test_api_version_optional_when_null(): void
    {
        // Establish a known version, then construct without one and
        // verify the prior version is left untouched.
        Stripe::setApiVersion('2020-08-27');
        new StripeClient('sk_test_x');
        $this->assertSame('2020-08-27', Stripe::getApiVersion());
    }

    public function test_api_version_set_when_provided(): void
    {
        new StripeClient('sk_test_x', '2025-08-27.basil');
        $this->assertSame('2025-08-27.basil', Stripe::getApiVersion());
    }

    public function test_empty_string_api_version_ignored(): void
    {
        Stripe::setApiVersion('2024-01-01');
        new StripeClient('sk_test_x', '');
        $this->assertSame('2024-01-01', Stripe::getApiVersion());
    }
}

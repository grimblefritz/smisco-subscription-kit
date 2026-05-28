<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit\Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Fake Stripe HTTP client. Install via:
 *
 *     \Stripe\ApiRequestor::setHttpClient($fake);
 *
 * Tests queue responses keyed by HTTP method + URL substring; the fake
 * returns the first matching response when the SDK makes a request, and
 * records every request for later assertions.
 *
 * Unmatched requests throw (catch in test setup to surface stripe-touching
 * paths the test forgot to cover).
 */
final class StripeHttpClientFake implements ClientInterface
{
    /** @var list<array{method:string, urlSubstr:string, body:string, status:int}> */
    private array $responses = [];

    /** @var list<array{method:string, url:string, params:array, headers:array}> */
    public array $recorded = [];

    /** Queue a JSON response for any request whose URL contains $urlSubstr. */
    public function queueJson(string $method, string $urlSubstr, array $body, int $status = 200): void
    {
        $this->responses[] = [
            'method'    => strtolower($method),
            'urlSubstr' => $urlSubstr,
            'body'      => json_encode($body, JSON_THROW_ON_ERROR),
            'status'    => $status,
        ];
    }

    /** Queue a raw-string response (e.g. malformed body, error envelope). */
    public function queueRaw(string $method, string $urlSubstr, string $body, int $status): void
    {
        $this->responses[] = [
            'method'    => strtolower($method),
            'urlSubstr' => $urlSubstr,
            'body'      => $body,
            'status'    => $status,
        ];
    }

    public function reset(): void
    {
        $this->responses = [];
        $this->recorded  = [];
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower((string)$method);
        $this->recorded[] = [
            'method'  => $method,
            'url'     => (string)$absUrl,
            'params'  => is_array($params) ? $params : [],
            'headers' => is_array($headers) ? $headers : [],
        ];

        foreach ($this->responses as $i => $resp) {
            if ($resp['method'] === $method && str_contains((string)$absUrl, $resp['urlSubstr'])) {
                unset($this->responses[$i]);
                $this->responses = array_values($this->responses);
                return [$resp['body'], $resp['status'], []];
            }
        }

        throw new \RuntimeException(
            "StripeHttpClientFake: unmatched {$method} {$absUrl} (no response queued)"
        );
    }
}

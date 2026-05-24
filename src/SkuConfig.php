<?php

declare(strict_types=1);

namespace Simnuxco\SubscriptionKit;

/**
 * Immutable map of SKU code → product config. Pure value object.
 *
 * Each SKU entry carries:
 *   - price_id     (string)   Stripe Price ID
 *   - mode         (string)   'subscription' (default) or 'payment'. SPV's
 *                             one-off SKUs use 'subscription' mode + the
 *                             is_oneoff flag (Checkout creates a Subscription
 *                             that the webhook patches with
 *                             cancel_at_period_end=true).
 *   - is_oneoff    (bool)     When true, CheckoutService will patch the
 *                             resulting Subscription with
 *                             cancel_at_period_end=true after creation.
 *   - trial_days   (?int)     Days of free trial. Null = no trial.
 *   - label        (string)   Human-friendly display name.
 *
 * Hosts construct via SkuConfig::fromArray() once at boot and pass to
 * CheckoutService / WebhookReceiver / etc.
 */
final class SkuConfig
{
    /** @var array<string,array{price_id:string,mode:string,is_oneoff:bool,trial_days:?int,label:string}> */
    private array $skus;

    /**
     * @param array<string,array<string,mixed>> $skus Map of code → config dict
     */
    public function __construct(array $skus)
    {
        $normalized = [];
        foreach ($skus as $code => $cfg) {
            if (!is_string($code) || $code === '') {
                throw new \InvalidArgumentException('SKU code must be a non-empty string');
            }
            if (!is_array($cfg)) {
                throw new \InvalidArgumentException("SKU config for '$code' must be an array");
            }
            if (empty($cfg['price_id']) || !is_string($cfg['price_id'])) {
                throw new \InvalidArgumentException("SKU '$code' is missing a non-empty price_id");
            }
            $mode = $cfg['mode'] ?? 'subscription';
            if (!in_array($mode, ['subscription', 'payment'], true)) {
                throw new \InvalidArgumentException(
                    "SKU '$code' has invalid mode '$mode' (expected 'subscription' or 'payment')"
                );
            }
            $normalized[$code] = [
                'price_id'   => $cfg['price_id'],
                'mode'       => $mode,
                'is_oneoff'  => (bool)($cfg['is_oneoff']  ?? false),
                'trial_days' => isset($cfg['trial_days']) ? (int)$cfg['trial_days'] : null,
                'label'      => (string)($cfg['label']    ?? $code),
            ];
        }
        $this->skus = $normalized;
    }

    /**
     * Convenience constructor — accepts any array shape the caller hands us
     * and normalizes through the constructor's validation.
     *
     * @param array<string,array<string,mixed>> $skus
     */
    public static function fromArray(array $skus): self
    {
        return new self($skus);
    }

    public function has(string $code): bool
    {
        return array_key_exists($code, $this->skus);
    }

    /**
     * @throws UnknownSkuException
     */
    public function priceId(string $code): string
    {
        return $this->require($code)['price_id'];
    }

    /**
     * @throws UnknownSkuException
     */
    public function mode(string $code): string
    {
        return $this->require($code)['mode'];
    }

    /**
     * @throws UnknownSkuException
     */
    public function isOneoff(string $code): bool
    {
        return $this->require($code)['is_oneoff'];
    }

    /**
     * @throws UnknownSkuException
     */
    public function trialDays(string $code): ?int
    {
        return $this->require($code)['trial_days'];
    }

    /**
     * @throws UnknownSkuException
     */
    public function label(string $code): string
    {
        return $this->require($code)['label'];
    }

    /**
     * Resolve a Stripe Price ID back to a SKU code, or null if none matches.
     * Used by webhook handlers when subscription metadata is missing the
     * sku_code (e.g. a subscription created outside the host app).
     */
    public function codeForPriceId(string $price_id): ?string
    {
        foreach ($this->skus as $code => $cfg) {
            if ($cfg['price_id'] === $price_id) {
                return $code;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->skus);
    }

    /** @return array<string,array{price_id:string,mode:string,is_oneoff:bool,trial_days:?int,label:string}> */
    public function all(): array
    {
        return $this->skus;
    }

    /**
     * @return array{price_id:string,mode:string,is_oneoff:bool,trial_days:?int,label:string}
     * @throws UnknownSkuException
     */
    private function require(string $code): array
    {
        if (!isset($this->skus[$code])) {
            throw new UnknownSkuException("Unknown SKU code: '$code'");
        }
        return $this->skus[$code];
    }
}

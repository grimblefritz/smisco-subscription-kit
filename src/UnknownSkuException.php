<?php

declare(strict_types=1);

namespace Smisco\SubscriptionKit;

use InvalidArgumentException;

/**
 * Thrown when a SKU code is looked up but isn't present in the configured map.
 */
class UnknownSkuException extends InvalidArgumentException
{
}

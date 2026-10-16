<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Events;

use Illuminate\Foundation\Events\Dispatchable;
use UniOne\Api\Webhook\Model\WebhookCallback;

/**
 * A webhook notification with a valid signature: all its events.
 */
final class WebhookReceived
{
    use Dispatchable;

    /**
     * @param array<string, mixed> $payload Decoded body of the request.
     */
    public function __construct(
        public readonly WebhookCallback $callback,
        public readonly array $payload,
    ) {
    }
}

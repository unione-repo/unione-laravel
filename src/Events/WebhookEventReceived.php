<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Events;

use Illuminate\Foundation\Events\Dispatchable;
use UniOne\Api\Webhook\Model\WebhookCallbackEventsByUserItemEventsItem;
use UniOne\Api\Webhook\Model\WebhookCallbackEventsByUserItem;

/**
 * One event of a webhook notification (email status, spam block...): $event->eventName, $event->eventData,
 * the user/project of the event in $user.
 */
final class WebhookEventReceived
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookCallbackEventsByUserItemEventsItem $event,
        public readonly WebhookCallbackEventsByUserItem $user,
    ) {
    }
}

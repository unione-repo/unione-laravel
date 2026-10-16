<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use UniOne\Api\Webhook\WebhookApi;
use UniOne\Wrapper\Events\WebhookEventReceived;
use UniOne\Wrapper\Events\WebhookReceived;
use UniOne\Wrapper\Webhook\WebhookSignature;

/**
 * Webhook endpoint: GET/HEAD -> 200 (URL check of webhook/set), POST -> signature check, WebhookReceived and a
 * WebhookEventReceived per event. Listeners must be fast: the API waits for 200 OK for 3 seconds only.
 */
final class WebhookController
{
    public function __invoke(Request $request, Repository $config, Dispatcher $events): Response
    {
        if (!$request->isMethod('POST')) {
            return new Response('OK');
        }

        $body = $request->getContent();
        $key = (string)($config->get('unione.webhook.key') ?: $config->get('unione.api_key'));
        if (!WebhookSignature::verify($body, $key)) {
            return new Response('Invalid signature', 401);
        }

        try {
            $callback = WebhookApi::parseCallback($body);
            /** @var array<string, mixed> $payload */
            $payload = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException|\TypeError|\ValueError $e) {
            return new Response('Invalid body', 400);
        }

        $events->dispatch(new WebhookReceived($callback, $payload));
        foreach ($callback->eventsByUser ?? [] as $user) {
            foreach ($user->events ?? [] as $event) {
                $events->dispatch(new WebhookEventReceived($event, $user));
            }
        }

        return new Response('OK');
    }
}

<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use UniOne\Api\Webhook\Model\WebhookCallback;
use UniOne\Client\ApiClient;
use UniOne\Client\ApiException;
use UniOne\Client\Configuration;
use UniOne\UniOneClient;
use UniOne\Wrapper\Events\WebhookEventReceived;
use UniOne\Wrapper\Events\WebhookReceived;
use UniOne\Wrapper\Facades\UniOne as ClientFacade;
use UniOne\Wrapper\Mailer\UnrecoverableTransportException;
use UniOne\Wrapper\UniOneServiceProvider;
use UniOne\Wrapper\Webhook\WebhookSignature;

/**
 * The package in a Laravel application of Orchestra Testbench. The API client of the container sends to
 * FakeTransport: no request leaves the machine.
 */
final class WrapperTest extends TestCase
{
    /** Base URL of the tests: a local address, never the default API host. */
    private const BASE_URL = 'http://127.0.0.1:9/api/v1';

    private const VARIABLES = [
        'UNIONE_API_KEY',
        'UNIONE_HOST',
        'UNIONE_WEBHOOK_ENABLED',
        'UNIONE_WEBHOOK_KEY',
    ];

    /** A webhook notification of the API documentation; WebhookSignature::sign() replaces the auth. */
    private const WEBHOOK = [
        'auth' => '00000000000000000000000000000000',
        'events_by_user' => [
            [
                'user_id' => 1,
                'project_id' => '6432890213745872',
                'events' => [
                    [
                        'event_name' => 'transactional_email_status',
                        'event_data' => [
                            'job_id' => '1a3Q2V-0000OZ-S0',
                            'email' => 'user@example.com',
                            'status' => 'delivered',
                            'event_time' => '2015-11-30 15:09:42',
                        ],
                    ],
                    [
                        'event_name' => 'transactional_spam_block',
                        'event_data' => [
                            'block_time' => '2015-11-30 15:09:42',
                            'block_type' => 'one_smtp',
                            'domain' => 'example.com',
                            'SMTP_blocks_count' => 1,
                            'domain_status' => 'blocked',
                        ],
                    ],
                ],
            ],
        ],
    ];

    private FakeTransport $transport;

    protected function setUp(): void
    {
        // the settings come from the environment, as in an application (config/unione.php)
        foreach (self::VARIABLES as $name) {
            self::setEnv($name, null);
        }
        self::setEnv('UNIONE_API_KEY', 'test-key');
        self::setEnv('UNIONE_HOST', self::BASE_URL);
        parent::setUp();
        $this->fakeTransport();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (self::VARIABLES as $name) {
            self::setEnv($name, null);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [UniOneServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'unione');
        $app['config']->set('mail.from', ['address' => 'shop@example.com', 'name' => 'Shop']);
    }

    public function testClientFromEnvironment(): void
    {
        $client = $this->app->make(UniOneClient::class);

        $this->assertSame($client, $this->app->make(UniOneClient::class));
        $this->assertSame($client, $this->app->make('unione'));
        $this->assertSame($client, ClientFacade::getFacadeRoot());
        $config = $this->app->make(Configuration::class);
        $this->assertSame('test-key', $config->getApiKey());
        $this->assertSame(self::BASE_URL, $config->getBaseUrl());
        $this->assertSame('laravel.2.4.0,' . ApiClient::PLATFORM, $config->getPlatform());
    }

    public function testMissingApiKey(): void
    {
        $this->app['config']->set('unione.api_key', null);
        $this->app->forgetInstance(Configuration::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(Configuration::class);
    }

    public function testMail(): void
    {
        $this->transport->add(FakeTransport::json(200, [
            'status' => 'success',
            'job_id' => 'job-1',
            'emails' => ['user@example.com'],
        ]));

        $sent = Mail::raw('Hello', static function ($message): void {
            $message->to('user@example.com', 'User')->subject('Test');
        });

        $this->assertCount(1, $this->transport->requests);
        $request = $this->transport->requests[0];
        $query = \http_build_query(['platform' => 'laravel.2.4.0,' . ApiClient::PLATFORM]);
        $this->assertSame(self::BASE_URL . '/email/send.json?' . $query, $request['url']);
        $this->assertSame('test-key', $request['headers']['X-API-KEY']);
        $message = \json_decode($request['body'], true)['message'];
        $recipient = ['email' => 'user@example.com', 'substitutions' => ['to_name' => 'User']];
        $this->assertSame([$recipient], $message['recipients']);
        $this->assertSame('shop@example.com', $message['from_email']);
        $this->assertSame('Shop', $message['from_name']);
        $this->assertSame('Test', $message['subject']);
        $this->assertSame('Hello', $message['body']['plaintext']);
        $this->assertSame('job-1', $sent->getMessageId());
    }

    public function testMailApiError(): void
    {
        $this->transport->add(FakeTransport::json(400, [
            'status' => 'error',
            'message' => 'Unknown recipient',
            'code' => 204,
        ]));

        $e = $this->sendError();

        // not retryable: symfony/messenger must not repeat it
        $this->assertInstanceOf(UnrecoverableTransportException::class, $e);
        $this->assertStringContainsString('email/send: Unknown recipient (API error 204, HTTP 400)', $e->getMessage());
        $previous = $e->getPrevious();
        $this->assertInstanceOf(ApiException::class, $previous);
        $this->assertSame(204, $previous->getErrorCode());
        $this->assertFalse($previous->isRetryable());
    }

    public function testMailRetryableError(): void
    {
        $this->transport->add(FakeTransport::json(503, [
            'status' => 'error',
            'message' => 'Service unavailable',
            'code' => 0,
        ]));

        $e = $this->sendError();

        $this->assertNotInstanceOf(UnrecoverableTransportException::class, $e);
        $this->assertInstanceOf(ApiException::class, $e->getPrevious());
        $this->assertTrue($e->getPrevious()->isRetryable());
    }

    public function testWebhook(): void
    {
        Event::fake([WebhookReceived::class, WebhookEventReceived::class]);
        $body = self::signedWebhook('test-key');

        $this->get('/unione/webhook')->assertOk();
        $this->postBody($body)->assertOk();

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatched(
            WebhookReceived::class,
            static fn (WebhookReceived $e): bool => $e->callback instanceof WebhookCallback,
        );
        Event::assertDispatchedTimes(WebhookEventReceived::class, 2);
        Event::assertDispatched(
            WebhookEventReceived::class,
            static fn (WebhookEventReceived $e): bool => 'user@example.com' === $e->event->eventData?->email,
        );
    }

    public function testWebhookWithWrongSignature(): void
    {
        Event::fake([WebhookReceived::class, WebhookEventReceived::class]);
        $body = self::signedWebhook('test-key');

        $this->postBody(\str_replace('user@', 'evil@', $body))->assertStatus(401);
        $this->postBody(self::signedWebhook('other-key'))->assertStatus(401);

        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(WebhookEventReceived::class);
    }

    public function testWebhookDisabled(): void
    {
        self::setEnv('UNIONE_WEBHOOK_ENABLED', 'false');
        $this->refreshApplication();

        $this->get('/unione/webhook')->assertNotFound();
        $this->postBody('{}')->assertNotFound();
    }

    public function testInstallCommand(): void
    {
        $published = $this->app->configPath('unione.php');
        $env = $this->app->basePath('.env');
        $example = $this->app->basePath('.env.example');
        $saved = [];
        foreach ([$published, $env, $example] as $file) {
            $saved[$file] = \is_file($file) ? \file_get_contents($file) : null;
        }
        @\unlink($published);
        \file_put_contents($env, "APP_NAME=Test\n");
        try {
            $this->artisan('unione:install')->assertExitCode(0);
            $this->artisan('unione:install')->assertExitCode(0);  // again: nothing is added twice

            $this->assertFileExists($published);
            $content = (string)\file_get_contents($env);
            $this->assertStringStartsWith("APP_NAME=Test\n", $content);
            $this->assertSame(1, \substr_count($content, "\nUNIONE_API_KEY=\n"));
        } finally {
            foreach ($saved as $file => $content) {
                null === $content ? @\unlink($file) : \file_put_contents($file, $content);
            }
        }
    }

    /**
     * The ApiClient of the container (the mailer) sends to a new FakeTransport.
     */
    private function fakeTransport(): void
    {
        $transport = $this->transport = new FakeTransport();
        $this->app->singleton(
            ApiClient::class,
            static fn ($app): ApiClient => new ApiClient($app->make(Configuration::class), $transport),
        );
    }

    private function postBody(string $body): TestResponse
    {
        return $this->call('POST', '/unione/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    private function sendError(): TransportException
    {
        try {
            Mail::raw('Hello', static function ($message): void {
                $message->to('user@example.com')->subject('Test');
            });
        } catch (TransportException $e) {
            return $e;
        }
        $this->fail('TransportException expected');
    }

    private static function signedWebhook(string $key): string
    {
        return WebhookSignature::sign(\json_encode(self::WEBHOOK, \JSON_THROW_ON_ERROR), $key);
    }

    private static function setEnv(string $name, ?string $value): void
    {
        if (null === $value) {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }
        \putenv("$name=$value");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}

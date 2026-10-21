<?php

declare(strict_types=1);

namespace UniOne\Wrapper;

use Illuminate\Contracts\Container\Container;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use UniOne\Client\ApiClient;
use UniOne\Client\Configuration;
use UniOne\UniOneClient;
use UniOne\Wrapper\Http\Controllers\WebhookController;
use UniOne\Wrapper\Mailer\UniOneTransport;

/**
 * Registers the UniOne API client (UniOneClient, ApiClient, Configuration) as singletons, the "unione" mail
 * transport and the webhook route.
 */
final class UniOneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/unione.php', 'unione');

        $this->app->singleton(Configuration::class, static function (Container $app): Configuration {
            /** @var array{api_key?: string|null, host?: string|null, timeout?: float|int|string|null} $config */
            $config = $app->make('config')->get('unione', []);
            $apiKey = (string)($config['api_key'] ?? '');
            if ($apiKey === '') {
                throw new \InvalidArgumentException('UniOne: set the API key in config/unione.php (UNIONE_API_KEY).');
            }
            $host = $config['host'] ?? null;

            return new Configuration(
                $apiKey,
                null === $host || '' === $host ? Configuration::DEFAULT_BASE_URL : Configuration::baseUrlFor((string)$host),
                (float)($config['timeout'] ?? 30),
                platform: 'laravel.2.2.3',
            );
        });
        $this->app->singleton(ApiClient::class, static fn (Container $app): ApiClient => new ApiClient($app->make(Configuration::class)));
        $this->app->singleton(UniOneClient::class, static fn (Container $app): UniOneClient => new UniOneClient($app->make(Configuration::class)));
        $this->app->alias(UniOneClient::class, 'unione');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/unione.php' => $this->app->configPath('unione.php')], 'unione-config');
        }

        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('unione', fn (): UniOneTransport => new UniOneTransport(
                $this->app->make(ApiClient::class),
                null,
                $this->app->bound('log') ? $this->app->make('log') : null,
            ));
        });

        $config = $this->app->make('config');
        if ($config->get('unione.webhook.enabled', true) && !$this->app->routesAreCached()) {
            $path = (string)$config->get('unione.webhook.path', '/unione/webhook');
            /** @var list<string> $middleware */
            $middleware = (array)$config->get('unione.webhook.middleware', []);
            $router = $this->app->make('router');
            $router->match(['GET', 'HEAD', 'POST'], $path, WebhookController::class)
                ->middleware($middleware)
                ->name('unione.webhook');
        }
    }
}

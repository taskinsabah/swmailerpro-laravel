<?php

namespace SabahWeb\SwMailerPro;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Commands\HealthCommand;
use SabahWeb\SwMailerPro\Commands\TestCommand;
use SabahWeb\SwMailerPro\Exceptions\ConfigurationException;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;
use SabahWeb\SwMailerPro\Transport\SwMailerProTransport;

class SwMailerProServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/swmailerpro.php', 'swmailerpro');

        $this->app->singleton('swmailerpro.client', function ($app) {
            $config = $app['config']['swmailerpro'];

            $url = $config['url'] ?? '';
            $key = $config['key'] ?? '';

            if (empty($url) || empty($key)) {
                throw new ConfigurationException(
                    'SwMailerPro: url ve key konfigürasyonu zorunludur. '
                    . 'SWMAILERPRO_URL ve SWMAILERPRO_KEY env değerlerini kontrol edin.'
                );
            }

            return new SwMailerProClient(
                baseUrl: $url,
                apiKey: $key,
                timeout: (int) ($config['client']['timeout'] ?? 30),
                retry: $config['client']['retry'] ?? ['times' => 2, 'sleep' => 200],
                connectTimeout: (int) ($config['client']['connect_timeout'] ?? 10),
                idempotency: (bool) ($config['idempotency'] ?? true),
            );
        });

        $this->app->alias('swmailerpro.client', SwMailerProClient::class);
    }

    public function boot(): void
    {
        // Config publish
        $this->publishes([
            __DIR__ . '/../config/swmailerpro.php' => $this->app->configPath('swmailerpro.php'),
        ], 'swmailerpro-config');

        // Mail transport kaydı
        Mail::extend('swmailerpro', function (array $config) {
            // Read here rather than captured at boot: an application that sets
            // config at runtime (tests do) must still get the values it set.
            $swConfig = (array) Config::get('swmailerpro', []);

            $url = (string) ($config['url'] ?? $swConfig['url'] ?? '');
            $key = (string) ($config['key'] ?? $swConfig['key'] ?? '');

            if (empty($url) || empty($key)) {
                throw new ConfigurationException(
                    'SwMailerPro: url ve key konfigürasyonu zorunludur. '
                    . 'SWMAILERPRO_URL ve SWMAILERPRO_KEY env değerlerini kontrol edin.'
                );
            }

            $transportConfig = (array) ($swConfig['transport'] ?? []);
            /** @var array{async?: bool, tracking?: array{open?: bool|null, click?: bool|null}} $defaults */
            $defaults = (array) ($swConfig['defaults'] ?? []);

            $client = new SwMailerProClient(
                baseUrl: $url,
                apiKey: $key,
                timeout: (int) ($transportConfig['timeout'] ?? 30),
                retry: (array) ($transportConfig['retry'] ?? ['times' => 2, 'sleep' => 200]),
                connectTimeout: (int) ($transportConfig['connect_timeout'] ?? 10),
                idempotency: (bool) ($swConfig['idempotency'] ?? true),
            );

            return new SwMailerProTransport(
                client: $client,
                payloadFactory: new PayloadFactory(),
                defaults: $defaults,
            );
        });

        // Artisan komutları
        if ($this->app->runningInConsole()) {
            $this->commands([
                HealthCommand::class,
                TestCommand::class,
            ]);
        }
    }
}

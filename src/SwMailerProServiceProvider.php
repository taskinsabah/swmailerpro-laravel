<?php

namespace SabahWeb\SwMailerPro;

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
                timeout: $config['client']['timeout'] ?? 30,
                retry: $config['client']['retry'] ?? ['times' => 2, 'sleep' => 200],
            );
        });

        $this->app->alias('swmailerpro.client', SwMailerProClient::class);
    }

    public function boot(): void
    {
        // Config publish
        $this->publishes([
            __DIR__ . '/../config/swmailerpro.php' => config_path('swmailerpro.php'),
        ], 'swmailerpro-config');

        // Mail transport kaydı
        Mail::extend('swmailerpro', function (array $config) {
            /** @var array<string, mixed> $swConfig */
            $swConfig = config('swmailerpro');

            $url = $config['url'] ?? $swConfig['url'] ?? '';
            $key = $config['key'] ?? $swConfig['key'] ?? '';

            if (empty($url) || empty($key)) {
                throw new ConfigurationException(
                    'SwMailerPro: url ve key konfigürasyonu zorunludur. '
                    . 'SWMAILERPRO_URL ve SWMAILERPRO_KEY env değerlerini kontrol edin.'
                );
            }

            $transportConfig = $swConfig['transport'] ?? [];
            $defaults = $swConfig['defaults'] ?? [];

            $client = new SwMailerProClient(
                baseUrl: $url,
                apiKey: $key,
                timeout: $transportConfig['timeout'] ?? 30,
                retry: $transportConfig['retry'] ?? ['times' => 2, 'sleep' => 200],
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

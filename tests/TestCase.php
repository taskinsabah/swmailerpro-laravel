<?php

namespace SabahWeb\SwMailerPro\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use SabahWeb\SwMailerPro\Facades\SwMailerPro;
use SabahWeb\SwMailerPro\SwMailerProServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SwMailerProServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'SwMailerPro' => SwMailerPro::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('swmailerpro.url', 'https://test-gateway.example.com');
        $app['config']->set('swmailerpro.key', 'test-api-key-123');
        $app['config']->set('mail.default', 'swmailerpro');
        $app['config']->set('mail.mailers.swmailerpro', [
            'transport' => 'swmailerpro',
        ]);
        $app['config']->set('mail.from', [
            'address' => 'noreply@example.com',
            'name' => 'Test App',
        ]);
    }
}

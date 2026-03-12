<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Tests\TestCase;

class CommandTest extends TestCase
{
    // ─── swmailerpro:health ─────────────────────────────────────────

    #[Test]
    public function health_command_shows_healthy_status(): void
    {
        Http::fake([
            'test-gateway.example.com/api/v1/health' => Http::response([
                'data' => [
                    'status' => 'healthy',
                    'uptime' => 86400,
                    'providers' => [
                        [
                            'provider' => 'mailchannels',
                            'state' => 'active',
                            'circuitBreaker' => ['state' => 'closed', 'failures' => 0],
                        ],
                    ],
                    'database' => ['status' => 'ok'],
                ],
            ], 200),
        ]);

        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('healthy')
            ->assertExitCode(0);
    }

    #[Test]
    public function health_command_fails_on_degraded_status(): void
    {
        Http::fake([
            'test-gateway.example.com/api/v1/health' => Http::response([
                'data' => ['status' => 'degraded'],
            ], 200),
        ]);

        $this->artisan('swmailerpro:health')
            ->assertExitCode(1);
    }

    #[Test]
    public function health_command_fails_on_api_error(): void
    {
        Http::fake([
            'test-gateway.example.com/*' => Http::response([
                'success' => false,
                'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid key'],
            ], 401),
        ]);

        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('API Hatası')
            ->assertExitCode(1);
    }

    // ─── swmailerpro:test ───────────────────────────────────────────

    #[Test]
    public function test_command_sends_test_email(): void
    {
        Http::fake([
            'test-gateway.example.com/api/v1/email/send-test' => Http::response([
                'success' => true,
                'data' => [
                    'status' => 'validated',
                    'message' => 'Email validated successfully',
                    'provider' => 'mailchannels',
                    'provider_message_id' => 'msg_test_1',
                ],
                'request_id' => 'req_test_cmd',
            ], 200),
        ]);

        $this->artisan('swmailerpro:test', ['--to' => 'dest@example.com'])
            ->expectsOutputToContain('başarıyla gönderildi')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/send-test')
                && $request['personalizations'][0]['to'][0]['email'] === 'dest@example.com';
        });
    }

    #[Test]
    public function test_command_requires_to_option(): void
    {
        $this->artisan('swmailerpro:test')
            ->expectsOutputToContain('--to')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_command_validates_email_format(): void
    {
        $this->artisan('swmailerpro:test', ['--to' => 'not-an-email'])
            ->expectsOutputToContain('Geçersiz')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_command_fails_on_api_error(): void
    {
        Http::fake([
            'test-gateway.example.com/*' => Http::response([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'from domain not verified',
                    'details' => ['from' => ['domain not verified']],
                ],
            ], 400),
        ]);

        $this->artisan('swmailerpro:test', ['--to' => 'dest@example.com'])
            ->expectsOutputToContain('API Hatası')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_command_uses_custom_from_and_subject(): void
    {
        Http::fake([
            'test-gateway.example.com/api/v1/email/send-test' => Http::response([
                'success' => true,
                'data' => ['status' => 'validated', 'message' => 'ok'],
                'request_id' => 'req_custom',
            ], 200),
        ]);

        $this->artisan('swmailerpro:test', [
            '--to' => 'dest@example.com',
            '--from' => 'custom@example.com',
            '--subject' => 'Custom Subject',
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request['from']['email'] === 'custom@example.com'
                && $request['subject'] === 'Custom Subject';
        });
    }
}

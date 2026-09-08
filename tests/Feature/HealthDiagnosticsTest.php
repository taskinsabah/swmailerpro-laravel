<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Tests\TestCase;

/**
 * The health command is the first thing an operator runs after a deploy, so the
 * two failures that stop mail while the API still answers "healthy" — a schema
 * left behind by a half-finished deploy, and a queue nothing is draining — have
 * to fail the command rather than scroll past in its output.
 */
class HealthDiagnosticsTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function fakeHealth(array $overrides = []): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => array_merge([
                    'status' => 'healthy',
                    'uptime' => 1234,
                    'version' => '1.0.0',
                    'schema_version' => 3,
                    'schema_version_expected' => 3,
                    'schema_status' => 'current',
                    'providers' => [
                        ['provider' => 'smtp2go', 'state' => 'healthy', 'circuitBreaker' => ['state' => 'closed', 'failures' => 0], 'errorRate' => 0.0],
                    ],
                    'suppression_list_size' => 17,
                    'queue_async_sends' => true,
                    'queue_stats' => ['pending' => 0, 'dead' => 0],
                    'queue_oldest_pending_ms' => null,
                    'dead_letter_count' => 0,
                ], $overrides),
            ], 200),
        ]);
    }

    #[Test]
    public function a_healthy_gateway_reports_schema_queue_and_suppression(): void
    {
        $this->fakeHealth();

        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('3/3 (current)')
            ->expectsOutputToContain('Engelli adres: 17')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_schema_left_behind_fails_the_check(): void
    {
        $this->fakeHealth([
            'schema_version' => 2,
            'schema_version_expected' => 3,
            'schema_status' => 'behind',
        ]);

        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('deploy yarım kalmış')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_queue_nobody_is_draining_fails_the_check(): void
    {
        $this->fakeHealth([
            'queue_stats' => ['pending' => 42, 'dead' => 0],
            'queue_oldest_pending_ms' => 20 * 60 * 1000,
        ]);

        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('worker çalışmıyor olabilir')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_briefly_pending_queue_is_not_treated_as_stuck(): void
    {
        $this->fakeHealth([
            'queue_stats' => ['pending' => 3, 'dead' => 0],
            'queue_oldest_pending_ms' => 4000,
        ]);

        $this->artisan('swmailerpro:health')->assertExitCode(0);
    }

    #[Test]
    public function dead_letters_are_surfaced(): void
    {
        $this->fakeHealth(['dead_letter_count' => 5]);

        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('ölü mektup kutusunda')
            ->assertExitCode(0);
    }
}

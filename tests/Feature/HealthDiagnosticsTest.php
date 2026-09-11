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
                    'queue_oldest_pending_ms' => 0,
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
    public function a_schema_ahead_of_the_package_fails_the_check(): void
    {
        // Gateway bu değeri gerçekten üretiyor: veritabanı bu paketin
        // beklediğinden yeniyse schema_status 'ahead' oluyor. Komut yalnızca
        // 'behind' arıyordu, dolayısıyla eski kodla yeni şemaya bakıp 0 dönüyordu.
        $this->fakeHealth([
            'schema_version' => 4,
            'schema_version_expected' => 3,
            'schema_status' => 'ahead',
        ]);

        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('kod eski kalmış')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_schema_the_gateway_could_not_read_fails_the_check(): void
    {
        // getSchemaVersion() patlarsa gateway schema_version null gönderiyor ve
        // durum 'unknown' oluyor. isset() null için false olduğundan kontrol en
        // baştan geri dönüyor, bilinmeyen bir şema sağlıklı sayılıyordu.
        $this->fakeHealth([
            'schema_version' => null,
            'schema_version_expected' => 3,
            'schema_status' => 'unknown',
        ]);

        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('Şema sürümü okunamadı')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_queue_the_gateway_could_not_read_fails_the_check(): void
    {
        // Kuyruk okuması patladığında gateway üç alanı birlikte null gönderiyor.
        // Bunları 0 saymak "bekleyen 0, ölü mektup 0" yazdırıyordu: gateway
        // hiçbir şey bilmediğini söylerken komut her şey yolunda diyordu. Boş bir
        // kuyruk bu şekli üretemez — gateway o durumda 0 gönderir, null değil.
        $this->fakeHealth([
            'queue_stats' => null,
            'dead_letter_count' => null,
            'queue_oldest_pending_ms' => null,
        ]);

        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('durum okunamadı')
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

    // ─── Eksik konfigürasyon ────────────────────────────────────────
    //
    // The command used to take the client as a handle() parameter, so the
    // container built it during method injection and the ConfigurationException
    // it throws escaped the command entirely. A fresh install — config
    // published, key not set yet — answered the diagnostic command with a stack
    // trace. These tests hold the command to the opposite: name the env var,
    // exit non-zero, and never reach the network.

    #[Test]
    public function a_missing_key_is_reported_instead_of_thrown(): void
    {
        config()->set('swmailerpro.key', '');

        // Beklentiler ayrı satırlara bakar: expectsOutputToContain her
        // beklentiyi tek bir yazma çağrısıyla eşleştirir, aynı satırdaki iki
        // parça birlikte aranamaz.
        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('SwMailerPro yapılandırması eksik: SWMAILERPRO_KEY tanımlı değil.')
            ->expectsOutputToContain('SWMAILERPRO_KEY=')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_missing_url_is_reported_instead_of_thrown(): void
    {
        config()->set('swmailerpro.url', '');

        $this->artisan('swmailerpro:health')
            ->expectsOutputToContain('SWMAILERPRO_URL tanımlı değil.')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_missing_key_never_reaches_the_gateway(): void
    {
        // Http::preventStrayRequests() is active, so an unfaked request would
        // throw. Asserting nothing was sent proves the command stopped at the
        // configuration check rather than dialling a gateway it cannot address.
        config()->set('swmailerpro.key', '');

        $this->artisan('swmailerpro:health')->assertExitCode(1);

        Http::assertNothingSent();
    }
}

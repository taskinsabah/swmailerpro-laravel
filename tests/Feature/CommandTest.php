<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Tests\TestCase;

class CommandTest extends TestCase
{
    /**
     * Gönderilebilir bir gönderici ayarlar.
     *
     * The suite's shared mail.from.address is noreply@example.com, and
     * example.com is reserved by RFC 2606 — the command now refuses it before
     * building a payload, exactly as it refuses Laravel's shipped
     * hello@example.com default. A test that wants to exercise anything past
     * sender resolution has to configure a domain that could really exist.
     */
    private function configureUsableSender(): void
    {
        config()->set('swmailerpro.defaults.from_email', 'test@alan-adiniz.com');
    }

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
        $this->configureUsableSender();

        Http::fake([
            'test-gateway.example.com/api/v1/email/send-test' => Http::response([
                'success' => true,
                // The gateway's real dry-run body: no status, no message id,
                // because nothing was sent. See EmailController::sendTest.
                'data' => [
                    'message' => 'Dry-run successful — email not sent',
                    'provider' => 'smtp2go',
                    'rendered_messages' => [['to' => 'dest@example.com']],
                ],
                'request_id' => 'req_test_cmd',
            ], 200),
        ]);

        $this->artisan('swmailerpro:test', ['--to' => 'dest@example.com'])
            ->expectsOutputToContain('mail GÖNDERİLMEDİ')
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
        $this->configureUsableSender();

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
            '--from' => 'custom@alan-adiniz.com',
            '--subject' => 'Custom Subject',
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request['from']['email'] === 'custom@alan-adiniz.com'
                && $request['subject'] === 'Custom Subject';
        });
    }

    // ─── Eksik konfigürasyon ────────────────────────────────────────

    #[Test]
    public function test_command_reports_a_missing_key_instead_of_throwing(): void
    {
        // Same hole as swmailerpro:health had: the client was a handle()
        // parameter, so the container built it — and threw — before the
        // command's own try/catch existed.
        $this->configureUsableSender();
        config()->set('swmailerpro.key', '');

        $this->artisan('swmailerpro:test', ['--to' => 'dest@example.com'])
            ->expectsOutputToContain('SwMailerPro yapılandırması eksik: SWMAILERPRO_KEY tanımlı değil.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    // ─── Gönderici çözümü ───────────────────────────────────────────

    #[Test]
    public function test_command_prefers_the_package_default_sender(): void
    {
        // mail.from.address belongs to the whole application; a tenant that
        // sends through this gateway from a different domain sets
        // SWMAILERPRO_FROM_EMAIL, and that has to win.
        config()->set('swmailerpro.defaults.from_email', 'paket@alan-adiniz.com');
        config()->set('mail.from.address', 'uygulama@baska-alan.com');

        Http::fake([
            'test-gateway.example.com/api/v1/email/send-test' => Http::response([
                'success' => true,
                'data' => ['message' => 'Dry-run successful — email not sent'],
            ], 200),
        ]);

        $this->artisan('swmailerpro:test', ['--to' => 'dest@example.com'])
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => $request['from']['email'] === 'paket@alan-adiniz.com');
    }

    #[Test]
    public function test_command_falls_back_to_the_application_sender(): void
    {
        // defaults.from_email ships as null, so an install that only ever set
        // MAIL_FROM_ADDRESS must keep working.
        config()->set('swmailerpro.defaults.from_email', null);
        config()->set('mail.from.address', 'uygulama@alan-adiniz.com');

        Http::fake([
            'test-gateway.example.com/api/v1/email/send-test' => Http::response([
                'success' => true,
                'data' => ['message' => 'Dry-run successful — email not sent'],
            ], 200),
        ]);

        $this->artisan('swmailerpro:test', ['--to' => 'dest@example.com'])
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => $request['from']['email'] === 'uygulama@alan-adiniz.com');
    }

    #[Test]
    public function test_command_refuses_the_shipped_laravel_placeholder(): void
    {
        // hello@example.com is what config/mail.php ships with. The gateway
        // resolves the tenant from the sender's domain, so this address can
        // only ever come back as TENANT_NOT_FOUND — an answer that says
        // nothing about the setting that caused it.
        config()->set('swmailerpro.defaults.from_email', null);
        config()->set('mail.from.address', 'hello@example.com');

        $this->artisan('swmailerpro:test', ['--to' => 'dest@alan-adiniz.com'])
            ->expectsOutputToContain('SWMAILERPRO_FROM_EMAIL')
            ->expectsOutputToContain('MAIL_FROM_ADDRESS')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    #[Test]
    public function test_command_refuses_a_reserved_sender_subdomain(): void
    {
        // The gateway matches these label-bounded (RESERVED_EMAIL_DOMAINS in
        // src/config/constants.ts): mail.example.com is reserved, but
        // notexample.com is somebody's real domain.
        config()->set('swmailerpro.defaults.from_email', 'test@mail.example.com');

        // Assert on text only the guard can produce. "example.com" alone also
        // appears in the stray-request error from the fake gateway host, so
        // this test passed against the unfixed command.
        $this->artisan('swmailerpro:test', ['--to' => 'dest@alan-adiniz.com'])
            ->expectsOutputToContain('Gönderici adresi kullanılamaz: test@mail.example.com')
            ->expectsOutputToContain('rezerve edilmiş bir alan adı')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    #[Test]
    public function test_command_accepts_a_domain_that_merely_looks_reserved(): void
    {
        config()->set('swmailerpro.defaults.from_email', 'test@notexample.com');

        Http::fake([
            'test-gateway.example.com/api/v1/email/send-test' => Http::response([
                'success' => true,
                'data' => ['message' => 'Dry-run successful — email not sent'],
            ], 200),
        ]);

        $this->artisan('swmailerpro:test', ['--to' => 'dest@alan-adiniz.com'])
            ->assertExitCode(0);
    }

    #[Test]
    public function test_command_warns_but_proceeds_for_a_reserved_sender_given_on_the_command_line(): void
    {
        // Gateway'in gönderici tarafında rezerve alan adı kontrolü yok; tenant
        // from.email'in alan adından çözülüyor. Bayrağı bilerek veren birini
        // kesmek, gateway'in kabul edeceği gönderimi kaçış yolu bırakmadan
        // durdurmak olurdu.
        Http::fake([
            'test-gateway.example.com/api/v1/email/send-test' => Http::response([
                'success' => true,
                'data' => ['message' => 'Dry-run successful — email not sent'],
            ], 200),
        ]);

        $this->artisan('swmailerpro:test', [
            '--to' => 'dest@alan-adiniz.com',
            '--from' => 'custom@example.org',
        ])
            ->expectsOutputToContain('Gönderici rezerve bir alan adında: custom@example.org')
            ->assertExitCode(0);

        Http::assertSentCount(1);
    }

    #[Test]
    public function test_command_names_both_env_vars_when_no_sender_is_configured(): void
    {
        config()->set('swmailerpro.defaults.from_email', null);
        config()->set('mail.from.address', null);

        $this->artisan('swmailerpro:test', ['--to' => 'dest@alan-adiniz.com'])
            ->expectsOutputToContain('SWMAILERPRO_FROM_EMAIL')
            ->expectsOutputToContain('MAIL_FROM_ADDRESS')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    // ─── Hata ayrıntıları ───────────────────────────────────────────
    //
    // validate() in the gateway builds details as a LIST of
    // {field, message, code} and sends that same shape for 400
    // VALIDATION_ERROR and 413 ATTACHMENT_TOO_LARGE alike
    // (src/middleware/validate.ts). Reading it as field => messages labelled
    // each row with its list index and printed "Array" for the object, so the
    // only actionable part of the error never reached the operator.

    /** @param array<int|string, mixed> $details */
    private function fakeErrorWithDetails(string $code, int $status, array $details): void
    {
        Http::fake([
            'test-gateway.example.com/*' => Http::response([
                'success' => false,
                'error' => [
                    'code' => $code,
                    'message' => 'Request validation failed',
                    'details' => $details,
                ],
            ], $status),
        ]);
    }

    #[Test]
    public function validation_details_are_rendered_in_the_gateway_shape(): void
    {
        $this->configureUsableSender();
        $this->fakeErrorWithDetails('VALIDATION_ERROR', 400, [
            ['field' => 'personalizations.0.to.0.email', 'message' => 'Invalid email', 'code' => 'invalid_string'],
            ['field' => 'subject', 'message' => 'String must contain at least 1 character(s)', 'code' => 'too_small'],
        ]);

        // Her beklenti tek bir satırla eşleşir; alan, mesaj ve kod aynı satırda
        // olduğundan tamamı birlikte aranıyor.
        $this->artisan('swmailerpro:test', ['--to' => 'dest@alan-adiniz.com'])
            ->expectsOutputToContain('personalizations.0.to.0.email: Invalid email (invalid_string)')
            ->expectsOutputToContain('subject: String must contain at least 1 character(s) (too_small)')
            ->assertExitCode(1);
    }

    #[Test]
    public function attachment_too_large_details_are_rendered_the_same_way(): void
    {
        // 413 carries the identical shape, and the client turns it into an
        // ApiException like any other error response — so a renderer written
        // for VALIDATION_ERROR alone would have gone silent here.
        $this->configureUsableSender();
        $this->fakeErrorWithDetails('ATTACHMENT_TOO_LARGE', 413, [
            ['field' => 'attachments.0.content', 'message' => 'Attachment exceeds the 10MB limit', 'code' => 'custom'],
        ]);

        $this->artisan('swmailerpro:test', ['--to' => 'dest@alan-adiniz.com'])
            ->expectsOutputToContain('attachments.0.content: Attachment exceeds the 10MB limit (custom)')
            ->assertExitCode(1);
    }

    #[Test]
    public function details_with_nested_values_do_not_raise_a_php_warning(): void
    {
        // implode() on an array member is a warning waiting to happen, and a
        // warning in the middle of a diagnostic is noise on top of the error
        // the operator came for. PHPUnit converts warnings to failures, so
        // this test fails loudly if interpolation regresses.
        $this->configureUsableSender();
        $this->fakeErrorWithDetails('VALIDATION_ERROR', 400, [
            ['field' => ['personalizations', 0, 'to'], 'message' => ['Invalid email', 'Domain unknown'], 'code' => null],
            'ham bir satır',
        ]);

        $this->artisan('swmailerpro:test', ['--to' => 'dest@alan-adiniz.com'])
            ->expectsOutputToContain('Invalid email, Domain unknown')
            ->expectsOutputToContain('ham bir satır')
            ->assertExitCode(1);
    }

    #[Test]
    public function legacy_field_to_messages_details_are_still_printed(): void
    {
        // Not the shape this gateway sends, but a proxy or an older build can
        // still answer with it — and a detail we cannot label beats silence.
        $this->configureUsableSender();
        $this->fakeErrorWithDetails('VALIDATION_ERROR', 400, [
            'from' => ['domain not verified'],
        ]);

        $this->artisan('swmailerpro:test', ['--to' => 'dest@alan-adiniz.com'])
            ->expectsOutputToContain('from: domain not verified')
            ->assertExitCode(1);
    }

    #[Test]
    public function the_description_says_the_command_does_not_send(): void
    {
        // php artisan list prints this line, and it used to promise a
        // delivered mail. /send-test is a dry run — providerManager.sendDryRun
        // — so anyone waiting for that mail waited forever.
        $description = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->all()['swmailerpro:test']->getDescription();

        $this->assertStringContainsString('dry-run', $description);
        $this->assertStringNotContainsString('gönderir', $description);
    }
}

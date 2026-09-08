<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Events\EmailFailed;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use SabahWeb\SwMailerPro\Exceptions\UnsupportedFeatureException;
use SabahWeb\SwMailerPro\Facades\SwMailerPro;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;
use SabahWeb\SwMailerPro\Tests\TestCase;

/**
 * Non-transactional gönderim bu istemciden istenemez.
 *
 * Testlerin yarısı reddi, yarısı reddin yayılmadığını gösteriyor: normal gönderim
 * bu değişiklikten etkilenmemeli, yoksa kapı fazla geniş kapanmış demektir.
 */
class CapabilityGuardTest extends TestCase
{
    // ─── Başlık kapısı (transport yolu) ──────────────────────────────────

    #[Test]
    public function the_transport_refuses_a_non_transactional_message_without_calling_the_gateway(): void
    {
        Event::fake([EmailFailed::class]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        try {
            Mail::raw('gövde', function ($message) {
                $message->to('dest@example.com')->subject('Konu');
                $message->getSymfonyMessage()->getHeaders()
                    ->addTextHeader('X-SwMailerPro-Transactional', 'false');
            });
            $this->fail('UnsupportedFeatureException bekleniyordu');
        } catch (UnsupportedFeatureException $e) {
            $this->assertStringContainsString('non-transactional', $e->getMessage());
            $this->assertStringContainsString('X-SwMailerPro-Transactional', $e->getMessage());
        }

        // Asıl iddia bu: gateway'e hiç çıkılmadı.
        Http::assertNothingSent();
        Event::assertDispatched(EmailFailed::class);
    }

    #[Test]
    public function a_typo_in_the_header_is_refused_instead_of_being_read_as_non_transactional(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        // filter_var "maybe"yi false'a çeviriyordu; yani yazım hatası sessizce
        // non-transactional bir gönderime dönüşüp gateway'den 400 alıyordu.
        $this->expectException(UnsupportedFeatureException::class);

        try {
            Mail::raw('gövde', function ($message) {
                $message->to('dest@example.com')->subject('Konu');
                $message->getSymfonyMessage()->getHeaders()
                    ->addTextHeader('X-SwMailerPro-Transactional', 'maybe');
            });
        } finally {
            Http::assertNothingSent();
        }
    }

    // ─── Regresyon kapısı: normal akış etkilenmiyor ──────────────────────

    #[Test]
    public function a_transactional_message_still_goes_out(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        Mail::raw('gövde', function ($message) {
            $message->to('dest@example.com')->subject('Konu');
            $message->getSymfonyMessage()->getHeaders()
                ->addTextHeader('X-SwMailerPro-Transactional', 'true');
        });

        Http::assertSent(fn ($request) => $request['transactional'] === true);
    }

    #[Test]
    public function a_message_without_the_header_still_goes_out_and_carries_no_flag(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        Mail::raw('gövde', function ($message) {
            $message->to('dest@example.com')->subject('Konu');
        });

        Http::assertSent(fn ($request) => ! isset($request['transactional']));
    }

    // ─── Ham payload kapısı (Facade / doğrudan client) ───────────────────

    #[Test]
    public function the_raw_payload_door_is_refused_too(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        try {
            SwMailerPro::send($this->payload(['transactional' => false]));
            $this->fail('UnsupportedFeatureException bekleniyordu');
        } catch (UnsupportedFeatureException $e) {
            $this->assertStringContainsString('false', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_zero_asks_for_the_same_thing_as_false_and_is_refused_the_same_way(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $this->expectException(UnsupportedFeatureException::class);

        try {
            SwMailerPro::send($this->payload(['transactional' => 0]));
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function a_non_boolean_true_is_refused_because_the_gateway_field_is_a_strict_boolean(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        // Gateway'de alan z.boolean(); "true" dizgesi bayrağı açmaz, tip hatası
        // verir. Geçirmek 400'ü ağa çıkardıktan sonra öğrenmek demek olurdu.
        $this->expectException(UnsupportedFeatureException::class);

        try {
            SwMailerPro::send($this->payload(['transactional' => 'true']));
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function a_raw_payload_with_a_real_true_still_goes_out(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        SwMailerPro::send($this->payload(['transactional' => true]));

        Http::assertSent(fn ($request) => $request['transactional'] === true);
    }

    #[Test]
    public function the_async_and_dry_run_doors_are_refused_too(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $client = app('swmailerpro.client');

        foreach (['sendAsync', 'sendTest'] as $method) {
            try {
                $client->{$method}($this->payload(['transactional' => false]));
                $this->fail($method . ' reddetmeliydi');
            } catch (UnsupportedFeatureException) {
                // beklenen
            }
        }

        Http::assertNothingSent();
    }

    // ─── Doğrulayıcı kapısı ──────────────────────────────────────────────

    #[Test]
    public function the_payload_validator_refuses_it_before_it_looks_for_missing_fields(): void
    {
        // Payload'da from da recipient da yok; yine de duyulması gereken hata
        // eksik alan değil, istenen yeteneğin desteklenmemesi.
        $this->expectException(UnsupportedFeatureException::class);

        (new PayloadFactory())->fromArray(['transactional' => false]);
    }

    // ─── Dokümante edilen sözleşme ───────────────────────────────────────

    #[Test]
    public function the_refusal_is_catchable_as_a_swmailerpro_exception(): void
    {
        // README "SwMailerProException yakalayın" diyor; yeni istisna da o
        // hiyerarşinin içinde olmalı, yoksa mevcut catch blokları ıskalar.
        $this->expectException(SwMailerProException::class);

        (new PayloadFactory())->fromArray($this->payload(['transactional' => false]));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'from' => ['email' => 'gonderen@example.com'],
            'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
            'subject' => 'Konu',
            'content' => [['type' => 'text/plain', 'value' => 'gövde']],
        ], $extra);
    }
}

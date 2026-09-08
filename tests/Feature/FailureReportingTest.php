<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Events\EmailFailed;
use SabahWeb\SwMailerPro\Events\EmailSent;
use SabahWeb\SwMailerPro\Exceptions\ApiException;
use SabahWeb\SwMailerPro\Exceptions\ConnectionFailedException;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use SabahWeb\SwMailerPro\Tests\TestCase;

/**
 * How failures reach the application. Each of these used to be reported as
 * something it was not: a delivery, a mystery, or nothing at all.
 */
class FailureReportingTest extends TestCase
{
    private function send(): void
    {
        Mail::raw('gövde', function ($message) {
            $message->to('dest@example.com')->subject('Konu');
        });
    }

    #[Test]
    public function an_unreachable_gateway_is_catchable_as_ours(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timed out'));

        try {
            app('swmailerpro.client')->health();
            $this->fail('bir istisna bekleniyordu');
        } catch (SwMailerProException $e) {
            // Documented contract: catching SwMailerProException catches everything
            // this package can throw. Illuminate's ConnectionException did not.
            $this->assertInstanceOf(ConnectionFailedException::class, $e);
            $this->assertStringContainsString('ulaşılamadı', $e->getMessage());
        }
    }

    #[Test]
    public function a_non_json_two_hundred_is_not_a_delivery(): void
    {
        // A proxy login page or a Cloudflare interstitial.
        Http::fake(['*' => Http::response('<html>Attention Required</html>', 200)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('JSON yanıt döndürmedi');

        app('swmailerpro.client')->send(['from' => ['email' => 'a@example.com']]);
    }

    #[Test]
    public function an_api_error_carries_the_request_id_for_tracing(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'subject required'],
                'request_id' => 'req_abc123',
            ], 400),
        ]);

        try {
            app('swmailerpro.client')->send(['from' => ['email' => 'a@example.com']]);
            $this->fail('bir istisna bekleniyordu');
        } catch (ApiException $e) {
            $this->assertSame('req_abc123', $e->requestId);
            $this->assertStringContainsString('req_abc123', $e->getMessage());
        }
    }

    #[Test]
    public function a_failure_while_building_the_payload_still_reports(): void
    {
        Event::fake([EmailFailed::class]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        try {
            // Malformed template data: PayloadFactory throws before any HTTP call.
            Mail::raw('gövde', function ($message) {
                $message->to('dest@example.com')->subject('Konu');
                $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'welcome');
                $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-SwMailerPro-Data', '{bozuk json');
            });
            $this->fail('bir istisna bekleniyordu');
        } catch (\Throwable) {
            // The point is the event, not the exception type.
        }

        Event::assertDispatched(EmailFailed::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function suppressed_recipients_are_surfaced(): void
    {
        Event::fake([EmailSent::class]);
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    'status' => 200,
                    'provider_message_id' => 'msg_9',
                    'suppressed_recipients' => ['bounced@example.com'],
                ],
                'request_id' => 'req_9',
            ], 200),
        ]);

        $this->send();

        Event::assertDispatched(EmailSent::class, function (EmailSent $event) {
            return $event->suppressedRecipients === ['bounced@example.com'];
        });
    }

    #[Test]
    public function every_recipient_suppressed_is_a_failure_not_a_send_with_an_empty_list(): void
    {
        // Dokümanlar uzun süre "tümü elenirse yanıt yine de 200 döner, tek işaret
        // suppressedRecipients" dedi. Gateway öyle davranmıyor: hepsi elenirse
        // suppressionCheck 422 döner, suppressed_recipients alanı hiç yazılmaz ve
        // EmailSent hiç yayınlanmaz. Bu test o iddiayı yerine sabitliyor.
        Event::fake([EmailSent::class, EmailFailed::class]);
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'request_id' => 'req_422',
                'error' => [
                    'code' => 'ALL_RECIPIENTS_SUPPRESSED',
                    'message' => 'All recipients are suppressed. Nothing was sent.',
                ],
            ], 422),
        ]);

        try {
            $this->send();
            $this->fail('bir istisna bekleniyordu');
        } catch (ApiException $e) {
            $this->assertSame('ALL_RECIPIENTS_SUPPRESSED', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }

        Event::assertNotDispatched(EmailSent::class);
        Event::assertDispatched(EmailFailed::class);
    }

    #[Test]
    public function a_throwing_failure_listener_does_not_replace_the_real_error(): void
    {
        // Başarı yolunda bu koruma vardı, hata yolunda yoktu: patlayan bir
        // EmailFailed dinleyicisinin istisnası asıl ApiException'ın YERİNE
        // geçiyor, üstelik previous olarak da taşınmadığı için gerçek sebep
        // tamamen kayboluyordu. Uygulama "audit log down" görüp gateway'in ne
        // dediğini hiç öğrenemiyordu.
        Http::fake(['*' => Http::response([
            'success' => false,
            'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'subject required'],
        ], 400)]);

        Event::listen(EmailFailed::class, function () {
            throw new \LogicException('audit log down');
        });

        try {
            $this->send();
            $this->fail('bir istisna bekleniyordu');
        } catch (ApiException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode);
        } finally {
            Event::forget(EmailFailed::class);
        }
    }

    #[Test]
    public function a_success_false_envelope_is_a_failure_even_with_a_200(): void
    {
        // İstemci yalnızca HTTP koduna bakıyordu. Zarfında success:false taşıyan
        // bir 200, teslim edilmemiş maili gönderilmiş gibi raporluyordu.
        Event::fake([EmailSent::class, EmailFailed::class]);
        Http::fake(['*' => Http::response([
            'success' => false,
            'request_id' => 'req_200_false',
            'error' => ['code' => 'PROVIDER_REJECTED', 'message' => 'provider said no'],
        ], 200)]);

        try {
            $this->send();
            $this->fail('bir istisna bekleniyordu');
        } catch (ApiException $e) {
            $this->assertSame('PROVIDER_REJECTED', $e->errorCode);
            $this->assertSame('req_200_false', $e->requestId);
        }

        Event::assertNotDispatched(EmailSent::class);
        Event::assertDispatched(EmailFailed::class);
    }

    #[Test]
    public function a_throwing_listener_does_not_turn_a_sent_mail_into_a_failure(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        Event::listen(EmailSent::class, function () {
            throw new \RuntimeException('dinleyici patladı');
        });

        // The mail is already gone; reporting a failure here would make the queue
        // retry it and the recipient would get it twice.
        $this->send();

        Http::assertSentCount(1);
    }
    #[Test]
    public function an_error_code_that_is_not_a_string_is_still_our_exception(): void
    {
        // Şema garanti değil: gateway error.code'u bir sağlayıcının ham
        // gövdesinden kopyalayabiliyor. Diziyi mesaja gömmek "Array to string
        // conversion" fırlatıyordu ve doğan ErrorException ne
        // SwMailerProException ne de TransportExceptionInterface olduğu için
        // dokümanın "SwMailerProException yakalayın" sözleşmesi kırılıyordu.
        Http::fake(['*' => Http::response([
            'success' => false,
            'error' => ['code' => ['A', 'B'], 'message' => ['x' => 1]],
        ], 400)]);

        try {
            app('swmailerpro.client')->send(['from' => ['email' => 'a@example.com']]);
            $this->fail('bir istisna bekleniyordu');
        } catch (ApiException $e) {
            $this->assertSame('UNKNOWN', $e->errorCode);
            $this->assertSame(400, $e->httpStatus);
            // Kod da mesaj da kullanılamazsa 400'ün sebebi hiçbir yerde
            // kalmıyor; ham gövde en azından onu taşıyor.
            $this->assertStringContainsString('"code":["A","B"]', $e->getMessage());
            $this->assertSame(['A', 'B'], $e->errorBody['error']['code']);
        }
    }

    #[Test]
    public function a_non_scalar_message_falls_back_to_the_body_but_keeps_the_code(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'error' => ['code' => 'VALIDATION_ERROR', 'message' => ['subject' => 'required']],
            'request_id' => 'req_nonscalar',
        ], 422)]);

        try {
            app('swmailerpro.client')->send(['from' => ['email' => 'a@example.com']]);
            $this->fail('bir istisna bekleniyordu');
        } catch (ApiException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode);
            $this->assertSame('req_nonscalar', $e->requestId);
            $this->assertStringContainsString('"subject":"required"', $e->getMessage());
        }
    }

    #[Test]
    public function an_error_field_that_is_not_an_object_reports_the_body(): void
    {
        // "error" bir metin: bugün de doğru davranıyor, ama kod yolu artık
        // is_array'den geçtiği için kilitlenmesi gerekiyor.
        Http::fake(['*' => Http::response([
            'success' => false,
            'error' => 'plain string error',
        ], 400)]);

        try {
            $this->send();
            $this->fail('bir istisna bekleniyordu');
        } catch (ApiException $e) {
            $this->assertSame('UNKNOWN', $e->errorCode);
            $this->assertStringContainsString('plain string error', $e->getMessage());
        }
    }
}

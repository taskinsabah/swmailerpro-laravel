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
            $message->to('dest@ornek.com.tr')->subject('Konu');
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

        app('swmailerpro.client')->send(['from' => ['email' => 'a@ornek.com.tr']]);
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
            app('swmailerpro.client')->send(['from' => ['email' => 'a@ornek.com.tr']]);
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
                $message->to('dest@ornek.com.tr')->subject('Konu');
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
                    'suppressed_recipients' => ['bounced@ornek.com.tr'],
                ],
                'request_id' => 'req_9',
            ], 200),
        ]);

        $this->send();

        Event::assertDispatched(EmailSent::class, function (EmailSent $event) {
            return $event->suppressedRecipients === ['bounced@ornek.com.tr'];
        });
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
}

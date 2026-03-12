<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Events\EmailFailed;
use SabahWeb\SwMailerPro\Events\EmailSent;
use SabahWeb\SwMailerPro\Tests\TestCase;

class SendEmailTest extends TestCase
{
    #[Test]
    public function full_flow_send_mailable_dispatches_event(): void
    {
        Event::fake([EmailSent::class, EmailFailed::class]);

        Http::fake([
            'test-gateway.example.com/api/v1/email/send' => Http::response([
                'success' => true,
                'data' => [
                    'status' => 'sent',
                    'message' => 'Email sent successfully',
                    'provider' => 'mailchannels',
                    'provider_message_id' => 'msg_abc123',
                ],
                'request_id' => 'req_full_1',
            ], 200),
        ]);

        Mail::to('user@example.com')->send(new TestMailable());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/email/send')
                && $request['from']['email'] === 'noreply@example.com'
                && $request['subject'] === 'Test Mailable Subject'
                && $request['personalizations'][0]['to'][0]['email'] === 'user@example.com';
        });

        Event::assertDispatched(EmailSent::class, function (EmailSent $event) {
            return $event->requestId === 'req_full_1'
                && $event->response['data']['provider'] === 'mailchannels';
        });
    }

    #[Test]
    public function full_flow_api_error_dispatches_failed_event(): void
    {
        Event::fake([EmailSent::class, EmailFailed::class]);

        Http::fake([
            'test-gateway.example.com/*' => Http::response([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid from address'],
            ], 400),
        ]);

        try {
            Mail::to('user@example.com')->send(new TestMailable());
            $this->fail('Expected exception');
        } catch (\Throwable) {
            // beklenen
        }

        Event::assertDispatched(EmailFailed::class);
        Event::assertNotDispatched(EmailSent::class);
    }

    #[Test]
    public function facade_send_calls_api(): void
    {
        Http::fake([
            'test-gateway.example.com/api/v1/email/send' => Http::response([
                'success' => true,
                'data' => ['status' => 'sent'],
                'request_id' => 'req_facade',
            ], 200),
        ]);

        $result = \SabahWeb\SwMailerPro\Facades\SwMailerPro::send([
            'from' => ['email' => 'api@example.com'],
            'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
            'subject' => 'Facade Test',
            'content' => [['type' => 'text/html', 'value' => '<p>Test</p>']],
        ]);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return $request['from']['email'] === 'api@example.com'
                && $request['subject'] === 'Facade Test';
        });
    }
}

/**
 * Feature testi için basit Mailable.
 */
class TestMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Test Mailable Subject',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<h1>Test</h1><p>Feature test mailable content.</p>',
        );
    }
}

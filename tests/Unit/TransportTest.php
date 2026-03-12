<?php

namespace SabahWeb\SwMailerPro\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Events\EmailFailed;
use SabahWeb\SwMailerPro\Events\EmailSent;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;
use SabahWeb\SwMailerPro\Tests\TestCase;
use SabahWeb\SwMailerPro\Transport\SwMailerProTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class TransportTest extends TestCase
{
    private SwMailerProTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $client = new SwMailerProClient(
            baseUrl: 'https://test-gateway.example.com',
            apiKey: 'test-key',
            timeout: 5,
            retry: ['times' => 0, 'sleep' => 0],
        );

        $this->transport = new SwMailerProTransport(
            client: $client,
            payloadFactory: new PayloadFactory(),
            defaults: [
                'async' => false,
                'tracking' => [
                    'open' => true,
                    'click' => false,
                ],
            ],
        );
    }

    #[Test]
    public function sends_email_via_sync_endpoint_and_dispatches_sent_event(): void
    {
        Event::fake([EmailSent::class, EmailFailed::class]);

        Http::fake([
            'test-gateway.example.com/api/v1/email/send' => Http::response([
                'success' => true,
                'data' => ['status' => 'sent', 'provider' => 'mailchannels'],
                'request_id' => 'req_tr_1',
            ], 200),
        ]);

        $email = $this->makeEmail();
        $this->transport->send($email);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/email/send')
                && !str_contains($request->url(), '/send-async')
                && $request['from']['email'] === 'sender@example.com'
                && $request['subject'] === 'Transport Test'
                && $request['personalizations'][0]['to'][0]['email'] === 'to@example.com';
        });

        Event::assertDispatched(EmailSent::class, function (EmailSent $event) {
            return $event->requestId === 'req_tr_1'
                && $event->payload['from']['email'] === 'sender@example.com';
        });

        Event::assertNotDispatched(EmailFailed::class);
    }

    #[Test]
    public function sends_email_via_async_endpoint_when_configured(): void
    {
        Event::fake([EmailSent::class]);

        Http::fake([
            'test-gateway.example.com/api/v1/email/send-async' => Http::response([
                'success' => true,
                'data' => ['status' => 'queued'],
                'request_id' => 'req_async',
            ], 202),
        ]);

        $client = new SwMailerProClient(
            baseUrl: 'https://test-gateway.example.com',
            apiKey: 'test-key',
            timeout: 5,
            retry: ['times' => 0, 'sleep' => 0],
        );

        $transport = new SwMailerProTransport(
            client: $client,
            payloadFactory: new PayloadFactory(),
            defaults: ['async' => true],
        );

        $transport->send($this->makeEmail());

        Http::assertSent(fn ($r) => str_contains($r->url(), '/send-async'));

        Event::assertDispatched(EmailSent::class);
    }

    #[Test]
    public function dispatches_failed_event_and_rethrows_on_error(): void
    {
        Event::fake([EmailSent::class, EmailFailed::class]);

        Http::fake([
            'test-gateway.example.com/*' => Http::response([
                'success' => false,
                'error' => ['code' => 'RATE_LIMIT', 'message' => 'Too many requests'],
            ], 429),
        ]);

        $this->expectException(\Throwable::class);

        try {
            $this->transport->send($this->makeEmail());
        } catch (\Throwable $e) {
            Event::assertDispatched(EmailFailed::class, function (EmailFailed $event) {
                return $event->payload['from']['email'] === 'sender@example.com'
                    && $event->exception instanceof \Throwable;
            });

            Event::assertNotDispatched(EmailSent::class);

            throw $e;
        }
    }

    #[Test]
    public function applies_tracking_defaults_to_payload(): void
    {
        Event::fake([EmailSent::class]);

        Http::fake([
            'test-gateway.example.com/api/v1/email/send' => Http::response([
                'success' => true,
                'data' => ['status' => 'sent'],
                'request_id' => 'req_trk',
            ], 200),
        ]);

        $this->transport->send($this->makeEmail());

        Http::assertSent(function ($request) {
            $tracking = $request['tracking_settings'] ?? [];
            return isset($tracking['open_tracking']['enable'])
                && $tracking['open_tracking']['enable'] === true
                && isset($tracking['click_tracking']['enable'])
                && $tracking['click_tracking']['enable'] === false;
        });
    }

    #[Test]
    public function to_string_returns_transport_name(): void
    {
        $this->assertEquals('swmailerpro', (string) $this->transport);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function makeEmail(): Email
    {
        return (new Email())
            ->from(new Address('sender@example.com', 'Sender'))
            ->to(new Address('to@example.com', 'Recipient'))
            ->subject('Transport Test')
            ->html('<p>Hello from transport test</p>');
    }
}

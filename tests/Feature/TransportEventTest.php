<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Mail\Events\MessageSent;
use SabahWeb\SwMailerPro\Events\EmailSent;
use SabahWeb\SwMailerPro\Tests\TestCase;

/**
 * A listener that treats every EmailSent as "delivered" reports success for
 * mail the gateway has merely accepted into its queue. The event has to say
 * which of the two happened.
 */
class TransportEventTest extends TestCase
{
    private function send(): void
    {
        Mail::raw('gövde', function ($message) {
            $message->to('dest@example.com')->subject('Konu');
        });
    }

    #[Test]
    public function a_synchronous_send_is_not_marked_queued(): void
    {
        Event::fake([EmailSent::class]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => [], 'request_id' => 'req_1'], 200)]);

        $this->send();

        Event::assertDispatched(EmailSent::class, function (EmailSent $event) {
            return $event->queued === false && $event->requestId === 'req_1';
        });
    }

    #[Test]
    public function an_async_send_is_marked_queued(): void
    {
        config()->set('swmailerpro.defaults.async', true);

        Event::fake([EmailSent::class]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => [], 'request_id' => 'req_2'], 202)]);

        $this->send();

        Event::assertDispatched(EmailSent::class, fn (EmailSent $event) => $event->queued === true);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/email/send-async'));
    }
    #[Test]
    public function a_sent_message_still_carries_its_control_headers(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $seen = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$seen) {
            $headers = $event->sent->getOriginalMessage()->getHeaders();
            $seen['campaign'] = $headers->get('X-SwMailerPro-Campaign')?->getBodyAsString();
            $seen['template'] = $headers->get('X-SwMailerPro-Template')?->getBodyAsString();
        });

        Mail::raw('gövde', function ($message) {
            $message->to('dest@example.com')->subject('Konu');
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-SwMailerPro-Campaign', 'camp_1');
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'tpl_1');
        });

        // Symfony gönderdiği mesajı SentMessage içinde dinleyicilere devrediyor;
        // payload üretirken onu tüketmek, uygulamanın kendi log satırını
        // kampanyasız bırakıyordu.
        $this->assertSame('camp_1', $seen['campaign'] ?? null);
        $this->assertSame('tpl_1', $seen['template'] ?? null);
    }
}

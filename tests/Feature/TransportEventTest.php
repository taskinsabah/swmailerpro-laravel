<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
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
}

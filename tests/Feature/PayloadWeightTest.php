<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Events\EmailSent;
use SabahWeb\SwMailerPro\Exceptions\PayloadTooLargeException;
use SabahWeb\SwMailerPro\Tests\TestCase;

/**
 * Two ways a big attachment used to cost more than the mail itself: it rode
 * along into the queue tables inside an event, and it was uploaded in full to
 * a gateway that was always going to refuse it.
 */
class PayloadWeightTest extends TestCase
{
    private function sendWithAttachment(int $bytes): void
    {
        Mail::raw('gövde', function ($message) use ($bytes) {
            $message->to('dest@ornek.com.tr')->subject('Konu');
            $message->attachData(str_repeat('A', $bytes), 'rapor.pdf', ['mime' => 'application/pdf']);
        });
    }

    // ─── The event no longer carries the file ───────────────────────────────

    #[Test]
    public function an_event_carries_what_the_attachment_was_not_the_attachment(): void
    {
        Event::fake([EmailSent::class]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $this->sendWithAttachment(64 * 1024);

        Event::assertDispatched(EmailSent::class, function (EmailSent $event) {
            $attachment = $event->payload['attachments'][0];

            // A queued listener serialises this event into the jobs table.
            return $attachment['content'] === null
                && $attachment['size_bytes'] === 64 * 1024
                && $attachment['filename'] === 'rapor.pdf'
                && $attachment['type'] === 'application/pdf';
        });
    }

    #[Test]
    public function the_gateway_still_receives_the_attachment(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $this->sendWithAttachment(1024);

        // Redaction is for the event only — the mail itself must be intact.
        Http::assertSent(function ($request) {
            $sent = $request['attachments'][0];

            return is_string($sent['content'])
                && strlen(base64_decode($sent['content'], true)) === 1024;
        });
    }

    // ─── Doomed uploads never leave ─────────────────────────────────────────

    #[Test]
    public function an_oversized_attachment_is_refused_before_the_upload(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);
        config()->set('swmailerpro.limits.attachment_bytes', 4096);

        try {
            $this->sendWithAttachment(8192);
            $this->fail('bir istisna bekleniyordu');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(PayloadTooLargeException::class, $this->rootCause($e));
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function the_message_names_the_file_and_the_ceiling(): void
    {
        config()->set('swmailerpro.limits.attachment_bytes', 4096);

        try {
            app('swmailerpro.client')->send([
                'from' => ['email' => 'sender@ornek.com.tr'],
                'personalizations' => [['to' => [['email' => 'dest@ornek.com.tr']]]],
                'subject' => 'Konu',
                'content' => [['type' => 'text/plain', 'value' => 'gövde']],
                'attachments' => [[
                    'content' => base64_encode(str_repeat('A', 8192)),
                    'filename' => 'rapor.pdf',
                ]],
            ]);
            $this->fail('bir istisna bekleniyordu');
        } catch (PayloadTooLargeException $e) {
            $this->assertStringContainsString('rapor.pdf', $e->getMessage());
            $this->assertStringContainsString('4096', $e->getMessage());
        }
    }

    #[Test]
    public function too_many_attachments_are_refused(): void
    {
        config()->set('swmailerpro.limits.attachments', 2);

        $this->expectException(PayloadTooLargeException::class);

        app('swmailerpro.client')->send([
            'from' => ['email' => 'sender@ornek.com.tr'],
            'personalizations' => [['to' => [['email' => 'dest@ornek.com.tr']]]],
            'attachments' => array_fill(0, 3, ['content' => base64_encode('x'), 'filename' => 'a.txt']),
        ]);
    }

    #[Test]
    public function a_zero_ceiling_hands_the_decision_back_to_the_gateway(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);
        config()->set('swmailerpro.limits.attachment_bytes', 0);

        $this->sendWithAttachment(8192);

        Http::assertSentCount(1);
    }

    private function rootCause(\Throwable $e): \Throwable
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }
}

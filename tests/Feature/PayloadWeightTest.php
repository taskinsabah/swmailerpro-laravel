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
            $message->to('dest@example.com')->subject('Konu');
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
                'from' => ['email' => 'sender@example.com'],
                'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
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
            'from' => ['email' => 'sender@example.com'],
            'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
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

    #[Test]
    public function an_attachment_whose_base64_is_all_s_characters_is_still_measured(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);
        config()->set('swmailerpro.limits.attachment_bytes', 4096);

        // Bu üç bayt base64'te yalnızca "s" harfine çözülür. Boşluk deseni yanlış
        // yazıldığında temizlik ekin tamamını siliyor, ölçüm 0 çıkıyor ve tavan ne
        // olursa olsun geçiyordu — canlıda 12 MB'lık bir ek 10 MB'ı böyle aştı.
        $bytes = str_repeat(chr(0xb2) . chr(0xcb) . chr(0x2c), 4096);
        $this->assertSame(12288, strlen($bytes));

        try {
            app('swmailerpro.client')->send([
                'from' => ['email' => 'sender@example.com'],
                'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
                'subject' => 'Konu',
                'content' => [['type' => 'text/plain', 'value' => 'gövde']],
                'attachments' => [[
                    'content' => base64_encode($bytes),
                    'filename' => 'hepsi-s.bin',
                ]],
            ]);
            $this->fail('bir istisna bekleniyordu');
        } catch (PayloadTooLargeException $e) {
            $this->assertStringContainsString('hepsi-s.bin', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_line_wrapped_attachment_is_not_measured_as_bigger_than_it_is(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);
        config()->set('swmailerpro.limits.attachment_bytes', 100000);

        // MIME base64'ü 76 karakterde bir böler; o satır sonları ekin baytı değil.
        // Sayıldıklarında ölçüm %2.6 şişiyor ve tavanın altındaki bir ek boşuna
        // reddediliyordu.
        $chunked = chunk_split(base64_encode(str_repeat('A', 99000)), 76);

        app('swmailerpro.client')->send([
            'from' => ['email' => 'sender@example.com'],
            'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
            'subject' => 'Konu',
            'content' => [['type' => 'text/plain', 'value' => 'gövde']],
            'attachments' => [[
                'content' => $chunked,
                'filename' => 'satirli.pdf',
            ]],
        ]);

        Http::assertSentCount(1);
    }

    // ─── Base64 şişmesi ölçülüyor ───────────────────────────────────────────

    #[Test]
    public function the_body_is_weighed_as_the_gateway_receives_it(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        // 60.000 baytlık ek gövdeye base64 olarak, yani 80.000 bayt olarak
        // giriyor. Çözülmüş baytları toplayan eski hesap 70.000'lik tavanın
        // altında kalıp geçiyordu — gateway ise aynı isteği tartıp reddederdi.
        config()->set('swmailerpro.limits.body_bytes', 70000);

        try {
            $this->sendWithAttachment(60000);
            $this->fail('bir istisna bekleniyordu');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(PayloadTooLargeException::class, $this->rootCause($e));
            $this->assertStringContainsString('mesaj boyutu', $this->rootCause($e)->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function the_header_overhead_the_gateway_adds_is_counted_here_too(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $payload = [
            'from' => ['email' => 'sender@example.com'],
            'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
            'subject' => 'Konu',
            'content' => [['type' => 'text/plain', 'value' => str_repeat('a', 4096)]],
        ];

        // Tavan tam JSON'un boyutu kadar: başlık payı sayılmazsa geçer.
        $json = json_encode($payload);
        $this->assertIsString($json);
        config()->set('swmailerpro.limits.body_bytes', strlen($json));

        $this->expectException(PayloadTooLargeException::class);

        app('swmailerpro.client')->send($payload);
    }

    // ─── Kuyruk tavanı ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function asyncPayload(int $bytes): array
    {
        return [
            'from' => ['email' => 'sender@example.com'],
            'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
            'subject' => 'Konu',
            'content' => [['type' => 'text/plain', 'value' => 'gövde']],
            'attachments' => [[
                'content' => base64_encode(str_repeat('A', $bytes)),
                'filename' => 'rapor.pdf',
            ]],
        ];
    }

    #[Test]
    public function a_payload_too_heavy_to_queue_never_leaves(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 202)]);
        config()->set('swmailerpro.limits.async_payload_bytes', 32 * 1024);

        try {
            app('swmailerpro.client')->sendAsync($this->asyncPayload(64 * 1024));
            $this->fail('bir istisna bekleniyordu');
        } catch (PayloadTooLargeException $e) {
            // Gateway'in 413'ü de aynı şeyi söylüyor: bu mesaj senkron uçtan
            // geçebilir.
            $this->assertStringContainsString('/api/v1/email/send', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function the_queue_ceiling_does_not_touch_the_synchronous_send(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);
        config()->set('swmailerpro.limits.async_payload_bytes', 32 * 1024);

        // Kuyruk tavanı gövde tavanından alçak; senkron gönderim onu hiç
        // görmemeli, yoksa istisna mesajının önerdiği çıkış yolu kapalı olurdu.
        app('swmailerpro.client')->send($this->asyncPayload(64 * 1024));

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_zero_queue_ceiling_hands_the_decision_back_to_the_gateway(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 202)]);
        config()->set('swmailerpro.limits.async_payload_bytes', 0);

        app('swmailerpro.client')->sendAsync($this->asyncPayload(64 * 1024));

        Http::assertSentCount(1);
    }

    #[Test]
    public function an_async_mailable_is_refused_before_the_upload(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 202)]);
        config()->set('swmailerpro.defaults.async', true);
        config()->set('swmailerpro.limits.async_payload_bytes', 32 * 1024);

        try {
            $this->sendWithAttachment(64 * 1024);
            $this->fail('bir istisna bekleniyordu');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(PayloadTooLargeException::class, $this->rootCause($e));
        }

        Http::assertNothingSent();
    }

    // ─── Şema tavanları ─────────────────────────────────────────────────────

    #[Test]
    public function a_subject_longer_than_the_header_line_is_refused(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        try {
            app('swmailerpro.client')->send([
                'from' => ['email' => 'sender@example.com'],
                'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
                'subject' => str_repeat('a', 999),
                'content' => [['type' => 'text/plain', 'value' => 'gövde']],
            ]);
            $this->fail('bir istisna bekleniyordu');
        } catch (PayloadTooLargeException $e) {
            $this->assertStringContainsString('konu uzunluğu', $e->getMessage());
            $this->assertStringContainsString('998', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    private function rootCause(\Throwable $e): \Throwable
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }
}

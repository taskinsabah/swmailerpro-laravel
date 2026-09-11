<?php

namespace SabahWeb\SwMailerPro\Tests\Unit;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Exceptions\PayloadTooLargeException;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;
use SabahWeb\SwMailerPro\Tests\TestCase;
use SabahWeb\SwMailerPro\Transport\SwMailerProTransport;

/**
 * Ekin boyutu iki yerde ölçülüyor: istemci tavanı uygularken, transport ise
 * ekin kendisini event'ten çıkarırken. İki ayrı aritmetik, reddedilen bir ekin
 * event'te başka bir boyutla görünmesi demekti.
 */
class AttachmentSizeTest extends TestCase
{
    /** 300 baytlık ek, MIME'ın 76 karakterde bir CRLF koyduğu hâliyle. */
    private function wrapped(): string
    {
        return chunk_split(base64_encode(str_repeat('A', 300)), 76, "\r\n");
    }

    private function transport(): SwMailerProTransport
    {
        return new class(
            new SwMailerProClient(baseUrl: 'https://test-gateway.example.com', apiKey: 'k'),
            new PayloadFactory(),
        ) extends SwMailerProTransport {
            /**
             * @param array<string, mixed> $payload
             * @return array<string, mixed>
             */
            public function eventPayload(array $payload): array
            {
                return $this->forEvent($payload);
            }
        };
    }

    #[Test]
    public function the_event_reports_the_attachment_its_true_size(): void
    {
        $payload = ['attachments' => [['content' => $this->wrapped(), 'filename' => 'a.bin']]];

        $redacted = $this->transport()->eventPayload($payload);

        $this->assertSame(300, $redacted['attachments'][0]['size_bytes']);
    }

    #[Test]
    public function the_event_and_the_ceiling_measure_the_same_attachment_alike(): void
    {
        $content = $this->wrapped();
        $client = new SwMailerProClient(
            baseUrl: 'https://test-gateway.example.com',
            apiKey: 'k',
            limits: ['attachment_bytes' => 299],
        );

        $redacted = $this->transport()->eventPayload(['attachments' => [['content' => $content]]]);
        $this->assertSame(300, $redacted['attachments'][0]['size_bytes']);

        // İstemci 300 deyip reddediyorsa event de 300 demeli.
        $this->expectException(PayloadTooLargeException::class);
        $client->send([
            'from' => ['email' => 'a@example.com'],
            'personalizations' => [['to' => [['email' => 'b@example.com']]]],
            'subject' => 'Konu',
            'content' => [['type' => 'text/plain', 'value' => 'gövde']],
            'attachments' => [['content' => $content]],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'from' => ['email' => 'a@example.com'],
            'personalizations' => [['to' => [['email' => 'b@example.com']]]],
            'subject' => 'Konu',
            'content' => [['type' => 'text/plain', 'value' => 'gövde']],
        ], $overrides);
    }

    /** @param array<string, int> $limits */
    private function client(array $limits = []): SwMailerProClient
    {
        return new SwMailerProClient(
            baseUrl: 'https://test-gateway.example.com',
            apiKey: 'k',
            limits: $limits,
        );
    }

    #[Test]
    public function the_body_ceiling_counts_the_attachment_as_base64(): void
    {
        // 3.000 bayt eki base64'te 4.000 karakter. Çözülmüş baytı sayan hesap
        // bu payload'ı 3.500'lük tavanın altında görüyordu; gateway ise gövdeyi
        // aldığı hâliyle tartıyor ve reddediyor.
        $client = $this->client(['body_bytes' => 3500, 'header_overhead_bytes' => 0]);

        $this->expectException(PayloadTooLargeException::class);
        $this->expectExceptionMessageMatches('/mesaj boyutu/');

        $client->send($this->payload([
            'attachments' => [['content' => base64_encode(str_repeat('A', 3000)), 'filename' => 'a.bin']],
        ]));
    }

    #[Test]
    public function a_payload_too_heavy_to_queue_names_the_synchronous_endpoint(): void
    {
        $client = $this->client(['async_payload_bytes' => 2048]);

        $this->expectException(PayloadTooLargeException::class);
        $this->expectExceptionMessageMatches('#/api/v1/email/send#');

        $client->sendAsync($this->payload([
            'attachments' => [['content' => base64_encode(str_repeat('A', 4096)), 'filename' => 'a.bin']],
        ]));
    }

    #[Test]
    public function a_config_without_the_queue_key_is_left_exactly_as_it_was(): void
    {
        // v1.0.0'dan kalma bir published config'de async_payload_bytes yok. O
        // kuruluma buradan bir tavan dayatmak, kendi config dosyasında hiçbir
        // yerde görünmeyen — ve env ile de ezemeyeceği, çünkü o satır da yok —
        // yeni bir ret demekti. Üstelik kuyruk gateway'de varsayılan olarak
        // kapalı; kapalıyken böyle bir tavan gerçekten yok.
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 202)]);

        $this->client()->sendAsync($this->payload([
            'attachments' => [['content' => base64_encode(str_repeat('A', 2 * 1024 * 1024)), 'filename' => 'a.bin']],
        ]));

        Http::assertSentCount(1);
    }

    #[Test]
    public function the_shipped_ceiling_refuses_what_the_queue_would_reject(): void
    {
        // Taze kurulumda paketle gelen config bu anahtarı 2 MiB ile taşıyor —
        // gateway'in QUEUE_MAX_PAYLOAD_BYTES varsayılanı.
        $client = $this->client(['async_payload_bytes' => 2 * 1024 * 1024]);

        $this->expectException(PayloadTooLargeException::class);

        $client->sendAsync($this->payload([
            'attachments' => [['content' => base64_encode(str_repeat('A', 2 * 1024 * 1024)), 'filename' => 'a.bin']],
        ]));
    }

    #[Test]
    public function an_attachment_filename_longer_than_the_schema_allows_is_refused(): void
    {
        $client = $this->client();
        $name = str_repeat('a', 253) . '.pdf'; // 257 karakter

        $this->expectException(PayloadTooLargeException::class);
        $this->expectExceptionMessageMatches('/ek dosya adı/u');

        $client->send($this->payload([
            'attachments' => [['content' => base64_encode('x'), 'filename' => $name]],
        ]));
    }

    #[Test]
    public function a_turkish_subject_is_measured_in_characters_not_bytes(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $client = $this->client();

        // 998 'ş' = 998 karakter ama 1.996 bayt. Gateway şeması karakter
        // sayıyor; bayta bakmak gateway'in kabul edeceği bir konuyu yerelde
        // reddetmek olurdu.
        $client->send($this->payload(['subject' => str_repeat('ş', 998)]));
        Http::assertSentCount(1);

        // Bir karakter fazlası ise gerçekten tavanın üstünde.
        $this->expectException(PayloadTooLargeException::class);
        $this->expectExceptionMessageMatches('/konu uzunluğu/u');

        $client->send($this->payload(['subject' => str_repeat('ş', 999)]));
    }
}

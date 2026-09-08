<?php

namespace SabahWeb\SwMailerPro\Tests\Unit;

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
}

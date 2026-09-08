<?php

namespace SabahWeb\SwMailerPro\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;
use SabahWeb\SwMailerPro\Tests\TestCase;
use Symfony\Component\Mime\Email;

/**
 * What the payload must carry across from a Symfony Email that it used to drop
 * on the floor: inline images, the application's own headers, and a subject
 * that is absent rather than null.
 */
class PayloadFidelityTest extends TestCase
{
    private function factory(): PayloadFactory
    {
        return new PayloadFactory();
    }

    private function email(): Email
    {
        return (new Email())
            ->from('sender@example.com')
            ->to('dest@example.com')
            ->subject('Konu')
            ->text('gövde');
    }

    #[Test]
    public function a_subject_less_email_omits_the_key_instead_of_sending_null(): void
    {
        $email = (new Email())->from('sender@example.com')->to('dest@example.com')->text('gövde');

        $payload = $this->factory()->fromEmail($email);

        $this->assertArrayNotHasKey('subject', $payload);
    }

    #[Test]
    public function an_embedded_image_stays_inline_and_keeps_its_cid(): void
    {
        $email = $this->email();
        $email->embed('binary-image-bytes', 'logo.png', 'image/png');

        $payload = $this->factory()->fromEmail($email);

        $this->assertCount(1, $payload['attachments']);
        $attachment = $payload['attachments'][0];

        $this->assertSame('inline', $attachment['disposition']);
        $this->assertArrayHasKey('content_id', $attachment);
        $this->assertNotSame('', $attachment['content_id']);
        $this->assertSame('logo.png', $attachment['filename']);
        $this->assertSame('image/png', $attachment['type']);
    }

    #[Test]
    public function a_laravel_embed_carries_the_cid_the_html_points_at(): void
    {
        $symfonyEmail = $this->email();
        $message = new \Illuminate\Mail\Message($symfonyEmail);

        // This is the call a Blade template makes: <img src="{{ $message->embed(...) }}">
        $reference = $message->embedData("binary-image-bytes", "logo.png", "image/png");

        $payload = $this->factory()->fromEmail($symfonyEmail);
        $attachment = $payload["attachments"][0];

        $this->assertSame("inline", $attachment["disposition"]);
        $this->assertSame($reference, "cid:" . $attachment["content_id"]);
    }

    #[Test]
    public function a_plain_attachment_is_not_marked_inline(): void
    {
        $email = $this->email();
        $email->attach('rapor-icerigi', 'rapor.pdf', 'application/pdf');

        $payload = $this->factory()->fromEmail($email);
        $attachment = $payload['attachments'][0];

        $this->assertNotSame('inline', $attachment['disposition'] ?? null);
        $this->assertArrayNotHasKey('content_id', $attachment);
    }

    #[Test]
    public function application_headers_travel_with_the_message(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Correlation-Id', 'abc-123');
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<https://example.com/u/1>');

        $payload = $this->factory()->fromEmail($email);

        $this->assertSame('abc-123', $payload['headers']['X-Correlation-Id']);
        $this->assertSame('<https://example.com/u/1>', $payload['headers']['List-Unsubscribe']);
    }

    #[Test]
    public function headers_the_gateway_derives_itself_are_not_forwarded(): void
    {
        $payload = $this->factory()->fromEmail($this->email());

        // A plain message carries nothing but headers the gateway derives itself,
        // so it must send no headers block at all.
        $this->assertArrayNotHasKey('headers', $payload);
    }

    #[Test]
    public function a_mailable_headers_block_reaches_the_gateway(): void
    {
        // Laravel's own API for this: Mailable::headers() -> Mailables\Headers.
        $symfonyEmail = $this->email();
        $message = new \Illuminate\Mail\Message($symfonyEmail);
        $message->getSymfonyMessage()->getHeaders()->addTextHeader("X-Campaign-Ref", "eylul-2026");
        $message->getSymfonyMessage()->getHeaders()->addIdHeader("References", ["parent@example.com"]);

        $payload = $this->factory()->fromEmail($symfonyEmail);

        $this->assertSame("eylul-2026", $payload["headers"]["X-Campaign-Ref"]);
        $this->assertArrayHasKey("References", $payload["headers"]);
    }

    #[Test]
    public function a_data_header_without_a_template_is_consumed_not_forwarded(): void
    {
        // Data yalnızca Template varken tüketiliyordu. Tek başına bırakıldığında
        // customHeaders'a düşüp gerçek bir mesaj başlığı olarak sağlayıcıya
        // gidiyor, yani template değişkenleri teslim edilen mailin başlıklarında
        // görünüyordu.
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Data', '{"order_id":"12345"}');

        $payload = $this->factory()->fromEmail($email);

        $this->assertArrayNotHasKey('X-SwMailerPro-Data', $payload['headers'] ?? []);
        $this->assertArrayNotHasKey('template_data', $payload);

        foreach (array_keys($payload['headers'] ?? []) as $name) {
            $this->assertStringNotContainsString('X-SwMailerPro', $name);
        }
    }

    #[Test]
    public function a_malformed_data_header_still_reports_without_a_template(): void
    {
        // Sessizce yutulan bozuk bir kontrol başlığı, bu hatanın geri dönüş yolu.
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Data', '{bozuk json');

        $this->expectException(SwMailerProException::class);

        $this->factory()->fromEmail($email);
    }

    #[Test]
    public function the_control_headers_never_reach_the_gateway(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'welcome');
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Campaign', 'eylul');
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Transactional', 'true');

        $payload = $this->factory()->fromEmail($email);

        $this->assertSame('welcome', $payload['template_id']);
        $this->assertSame('eylul', $payload['campaign_id']);
        $this->assertTrue($payload['transactional']);

        foreach (array_keys($payload['headers'] ?? []) as $name) {
            $this->assertStringNotContainsString('X-SwMailerPro', $name);
        }
    }
}

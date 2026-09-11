<?php

namespace SabahWeb\SwMailerPro\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;
use SabahWeb\SwMailerPro\Tests\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\ParameterizedHeader;

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
    // ─── Başlıklar gateway'e ham gider ───────────────────────────────────

    #[Test]
    public function an_ascii_header_travels_unchanged(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Correlation-Id', 'abc-123');

        $payload = $this->factory()->fromEmail($email);

        $this->assertSame('abc-123', $payload['headers']['X-Correlation-Id']);
    }

    #[Test]
    public function a_turkish_header_reaches_the_gateway_as_raw_utf8(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Customer-Name', 'Ayşe Yılmaz');

        $payload = $this->factory()->fromEmail($email);

        $this->assertSame('Ayşe Yılmaz', $payload['headers']['X-Customer-Name']);
    }

    #[Test]
    public function a_long_turkish_header_carries_no_fold(): void
    {
        $value = str_repeat('şğüöç-katlama ', 20);
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Uzun-Not', $value);

        $payload = $this->factory()->fromEmail($email);

        $this->assertSame($value, $payload['headers']['X-Uzun-Not']);
        $this->assertStringNotContainsString("\r", $payload['headers']['X-Uzun-Not']);
        $this->assertStringNotContainsString("\n", $payload['headers']['X-Uzun-Not']);
    }

    #[Test]
    public function a_line_break_in_a_header_value_stops_the_send(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Note', "ok\r\nBcc: spy@evil.test");

        $this->expectException(SwMailerProException::class);
        $this->expectExceptionMessageMatches('/X-Note/');

        $this->factory()->fromEmail($email);
    }

    #[Test]
    public function a_bare_line_feed_in_a_header_value_stops_the_send(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Note', "ok\nBcc: spy@evil.test");

        $this->expectException(SwMailerProException::class);

        $this->factory()->fromEmail($email);
    }

    #[Test]
    public function a_line_break_in_a_header_name_stops_the_send(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader("X-Note\r\nBcc", 'spy@evil.test');

        $this->expectException(SwMailerProException::class);

        $this->factory()->fromEmail($email);
    }

    #[Test]
    public function a_parameterized_header_keeps_its_parameters(): void
    {
        $email = $this->email();
        $email->getHeaders()->add(
            new ParameterizedHeader('X-Param', 'attachment', ['filename' => 'räksmörgås.txt'])
        );

        $payload = $this->factory()->fromEmail($email);

        $this->assertSame(
            "attachment; filename*=utf-8''r%C3%A4ksm%C3%B6rg%C3%A5s.txt",
            $payload['headers']['X-Param'],
        );
    }

    #[Test]
    public function template_data_with_unescaped_unicode_still_decodes(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'siparis-onayi');
        $email->getHeaders()->addTextHeader(
            'X-SwMailerPro-Data',
            json_encode(['name' => 'Ayşe'], JSON_UNESCAPED_UNICODE),
        );

        $payload = $this->factory()->fromEmail($email);

        $this->assertSame(['name' => 'Ayşe'], $payload['template_data']);
    }

    #[Test]
    public function an_inline_part_is_named_after_the_cid_the_html_points_at(): void
    {
        // The SMTP2GO path drops content_id and keys the inline part by its
        // filename, so an embed whose cid is "abc@symfony" but whose filename is
        // "logo.png" renders as a broken image. The two have to agree.
        $symfonyEmail = $this->email();
        $message = new \Illuminate\Mail\Message($symfonyEmail);

        $reference = $message->embedData('binary-image-bytes', 'logo.png', 'image/png');

        $payload = $this->factory()->fromEmail($symfonyEmail);
        $attachment = $payload['attachments'][0];

        $this->assertSame($reference, 'cid:' . $attachment['content_id']);
        $this->assertSame($attachment['content_id'], $attachment['filename']);
        // MailChannels resolves by content_id and must keep working.
        $this->assertNotSame('', $attachment['content_id']);
        $this->assertSame('image/png', $attachment['type']);
    }

    #[Test]
    public function a_regular_attachment_keeps_its_own_filename(): void
    {
        // Yalnızca inline parçalar yeniden adlandırılıyor; alıcının kaydettiği
        // dosyanın adı mesajın anlamının parçası.
        $email = $this->email();
        $email->attach('rapor-icerigi', 'rapor.pdf', 'application/pdf');

        $payload = $this->factory()->fromEmail($email);

        $this->assertSame('rapor.pdf', $payload['attachments'][0]['filename']);
    }

    #[Test]
    public function an_inline_part_that_is_not_an_image_keeps_its_extension(): void
    {
        // Inline parçanın adını cid yapmak yalnızca görseller için doğru. Cid'in
        // uzantısı yok; gateway ise engelli dosyayı dosya adının uzantısından
        // tanıyor. Her inline parçayı yeniden adlandırmak, bugün 400 ile
        // reddedilen bir .bat'ı o kontrolün yanından geçirirdi.
        $email = $this->email();
        $email->embed('@echo off', 'kurulum.bat', 'text/plain');

        $payload = $this->factory()->fromEmail($email);

        $this->assertSame('kurulum.bat', $payload['attachments'][0]['filename']);
    }

    #[Test]
    public function every_reply_to_address_reaches_the_gateway(): void
    {
        // reply_to tek adres kabul ediyor; ikincisi ve sonrası eskiden sessizce
        // düşüyor, gönderim yine başarılı raporlanıyordu.
        $email = $this->email()->replyTo(
            new Address('destek@example.com', 'Destek'),
            new Address('satis@example.com', 'Satış'),
        );

        $payload = $this->factory()->fromEmail($email);

        $this->assertArrayNotHasKey('reply_to', $payload);
        $this->assertSame(
            '"Destek" <destek@example.com>, "Satış" <satis@example.com>',
            $payload['headers']['Reply-To'],
        );
    }

    #[Test]
    public function a_single_reply_to_still_uses_the_schema_field(): void
    {
        $email = $this->email()->replyTo(new Address('destek@example.com', 'Destek'));

        $payload = $this->factory()->fromEmail($email);

        $this->assertSame('destek@example.com', $payload['reply_to']['email']);
        $this->assertSame('Destek', $payload['reply_to']['name']);
        // İki alan birden gitmemeli: ikisi de aynı başlığı açıyor.
        $this->assertArrayNotHasKey('Reply-To', $payload['headers'] ?? []);
    }

    #[Test]
    public function a_header_the_gateway_refuses_stops_the_send_instead_of_vanishing(): void
    {
        // Gateway bunlara 400 VALIDATION_ERROR veriyor. Sessizce çıkarmak,
        // iletilen mesajın kimin tarafından yeniden gönderildiğini silmek olurdu.
        $email = $this->email();
        $email->getHeaders()->addTextHeader('Resent-From', 'ilk-gonderen@example.com');

        $this->expectException(SwMailerProException::class);
        $this->expectExceptionMessageMatches('/Resent-From/');

        $this->factory()->fromEmail($email);
    }
}

<?php

namespace SabahWeb\SwMailerPro\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;
use SabahWeb\SwMailerPro\Tests\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\DataPart;

class PayloadFactoryTest extends TestCase
{
    private PayloadFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new PayloadFactory();
    }

    // ─── fromEmail ──────────────────────────────────────────────────

    #[Test]
    public function from_email_maps_from_address(): void
    {
        $email = $this->makeEmail();

        $payload = $this->factory->fromEmail($email);

        $this->assertEquals('sender@example.com', $payload['from']['email']);
        $this->assertEquals('Sender', $payload['from']['name']);
    }

    #[Test]
    public function from_email_maps_to_recipients(): void
    {
        $email = $this->makeEmail();

        $payload = $this->factory->fromEmail($email);

        $to = $payload['personalizations'][0]['to'];
        $this->assertCount(1, $to);
        $this->assertEquals('recipient@example.com', $to[0]['email']);
    }

    #[Test]
    public function from_email_maps_cc_and_bcc(): void
    {
        $email = $this->makeEmail()
            ->cc(new Address('cc@example.com', 'CC User'))
            ->bcc('bcc@example.com');

        $payload = $this->factory->fromEmail($email);

        $this->assertCount(1, $payload['personalizations'][0]['cc']);
        $this->assertEquals('cc@example.com', $payload['personalizations'][0]['cc'][0]['email']);
        $this->assertEquals('CC User', $payload['personalizations'][0]['cc'][0]['name']);

        $this->assertCount(1, $payload['personalizations'][0]['bcc']);
        $this->assertEquals('bcc@example.com', $payload['personalizations'][0]['bcc'][0]['email']);
    }

    #[Test]
    public function from_email_omits_cc_bcc_when_empty(): void
    {
        $email = $this->makeEmail();

        $payload = $this->factory->fromEmail($email);

        $this->assertArrayNotHasKey('cc', $payload['personalizations'][0]);
        $this->assertArrayNotHasKey('bcc', $payload['personalizations'][0]);
    }

    #[Test]
    public function from_email_maps_reply_to(): void
    {
        $email = $this->makeEmail()
            ->replyTo('reply@example.com');

        $payload = $this->factory->fromEmail($email);

        $this->assertEquals('reply@example.com', $payload['reply_to']['email']);
    }

    #[Test]
    public function from_email_maps_subject(): void
    {
        $email = $this->makeEmail();

        $payload = $this->factory->fromEmail($email);

        $this->assertEquals('Test Subject', $payload['subject']);
    }

    #[Test]
    public function from_email_maps_text_and_html_content(): void
    {
        $email = $this->makeEmail()
            ->text('Plain text body')
            ->html('<h1>HTML body</h1>');

        $payload = $this->factory->fromEmail($email);

        $this->assertCount(2, $payload['content']);
        $this->assertEquals('text/plain', $payload['content'][0]['type']);
        $this->assertEquals('Plain text body', $payload['content'][0]['value']);
        $this->assertEquals('text/html', $payload['content'][1]['type']);
        $this->assertEquals('<h1>HTML body</h1>', $payload['content'][1]['value']);
    }

    #[Test]
    public function from_email_maps_html_only(): void
    {
        $email = $this->makeEmail()
            ->html('<p>Only HTML</p>');

        $payload = $this->factory->fromEmail($email);

        $this->assertCount(1, $payload['content']);
        $this->assertEquals('text/html', $payload['content'][0]['type']);
    }

    #[Test]
    public function from_email_maps_attachments(): void
    {
        $email = $this->makeEmail()
            ->text('Body')
            ->attach('file content here', 'document.pdf', 'application/pdf');

        $payload = $this->factory->fromEmail($email);

        $this->assertArrayHasKey('attachments', $payload);
        $this->assertCount(1, $payload['attachments']);
        $this->assertEquals('document.pdf', $payload['attachments'][0]['filename']);
        $this->assertEquals(base64_encode('file content here'), $payload['attachments'][0]['content']);
        $this->assertNotEmpty($payload['attachments'][0]['type']);
    }

    #[Test]
    public function from_email_handles_attachment_mime_fallback(): void
    {
        // attach without explicit MIME → factory should fallback to application/octet-stream
        $email = $this->makeEmail()
            ->text('Body')
            ->attach('binary data', 'unknown.bin');

        $payload = $this->factory->fromEmail($email);

        $this->assertNotEmpty($payload['attachments'][0]['type']);
    }

    #[Test]
    public function from_email_parses_template_header(): void
    {
        $email = $this->makeEmail()->text('ignored');
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'tpl_welcome');

        $payload = $this->factory->fromEmail($email);

        $this->assertEquals('tpl_welcome', $payload['template_id']);
    }

    #[Test]
    public function from_email_parses_template_data_header(): void
    {
        $email = $this->makeEmail()->text('ignored');
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'tpl_order');
        $email->getHeaders()->addTextHeader(
            'X-SwMailerPro-Data',
            json_encode(['order_id' => '12345', 'total' => '99.90'])
        );

        $payload = $this->factory->fromEmail($email);

        $this->assertEquals('tpl_order', $payload['template_id']);
        $this->assertEquals(['order_id' => '12345', 'total' => '99.90'], $payload['template_data']);
    }

    #[Test]
    public function from_email_throws_on_invalid_template_data_json(): void
    {
        $email = $this->makeEmail()->text('body');
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'tpl_x');
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Data', '{invalid json');

        $this->expectException(SwMailerProException::class);
        $this->expectExceptionMessageMatches('/template_data.*JSON/i');

        $this->factory->fromEmail($email);
    }

    #[Test]
    public function from_email_unsets_content_when_template_and_no_body(): void
    {
        $email = $this->makeEmail();
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'tpl_only');
        // No text or html body

        $payload = $this->factory->fromEmail($email);

        $this->assertArrayNotHasKey('content', $payload);
        $this->assertEquals('tpl_only', $payload['template_id']);
    }

    #[Test]
    public function from_email_keeps_content_when_template_with_body(): void
    {
        $email = $this->makeEmail()->html('<p>Fallback</p>');
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'tpl_with_body');

        $payload = $this->factory->fromEmail($email);

        $this->assertEquals('tpl_with_body', $payload['template_id']);
        $this->assertArrayHasKey('content', $payload);
        $this->assertNotEmpty($payload['content']);
    }

    #[Test]
    public function from_email_parses_campaign_header(): void
    {
        $email = $this->makeEmail()->text('body');
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Campaign', 'campaign_summer_2025');

        $payload = $this->factory->fromEmail($email);

        $this->assertEquals('campaign_summer_2025', $payload['campaign_id']);
    }

    #[Test]
    public function from_email_parses_transactional_header(): void
    {
        $email = $this->makeEmail()->text('body');
        $email->getHeaders()->addTextHeader('X-SwMailerPro-Transactional', 'true');

        $payload = $this->factory->fromEmail($email);

        $this->assertTrue($payload['transactional']);
    }

    #[Test]
    public function from_email_address_without_name(): void
    {
        $email = (new Email())
            ->from('bare@example.com')
            ->to('to@example.com')
            ->subject('Test')
            ->text('body');

        $payload = $this->factory->fromEmail($email);

        $this->assertEquals('bare@example.com', $payload['from']['email']);
        $this->assertArrayNotHasKey('name', $payload['from']);
    }

    // ─── fromArray ──────────────────────────────────────────────────

    #[Test]
    public function from_array_returns_data_when_valid(): void
    {
        $data = $this->validPayloadArray();

        $result = $this->factory->fromArray($data);

        $this->assertEquals($data, $result);
    }

    #[Test]
    public function from_array_throws_when_from_missing(): void
    {
        $data = $this->validPayloadArray();
        unset($data['from']);

        $this->expectException(SwMailerProException::class);
        $this->expectExceptionMessageMatches('/from/i');

        $this->factory->fromArray($data);
    }

    #[Test]
    public function from_array_throws_when_from_email_missing(): void
    {
        $data = $this->validPayloadArray();
        $data['from'] = ['name' => 'No Email'];

        $this->expectException(SwMailerProException::class);
        $this->expectExceptionMessageMatches('/from.*email/i');

        $this->factory->fromArray($data);
    }

    #[Test]
    public function from_array_throws_when_no_recipients(): void
    {
        $data = $this->validPayloadArray();
        $data['personalizations'] = [];

        $this->expectException(SwMailerProException::class);
        $this->expectExceptionMessageMatches('/recipient/i');

        $this->factory->fromArray($data);
    }

    #[Test]
    public function from_array_throws_when_personalizations_to_empty(): void
    {
        $data = $this->validPayloadArray();
        $data['personalizations'] = [['to' => []]];

        $this->expectException(SwMailerProException::class);
        $this->expectExceptionMessageMatches('/recipient/i');

        $this->factory->fromArray($data);
    }

    #[Test]
    public function from_array_throws_when_no_subject_and_no_template(): void
    {
        $data = $this->validPayloadArray();
        unset($data['subject']);

        $this->expectException(SwMailerProException::class);
        $this->expectExceptionMessageMatches('/subject.*template_id/i');

        $this->factory->fromArray($data);
    }

    #[Test]
    public function from_array_accepts_template_id_without_subject(): void
    {
        $data = $this->validPayloadArray();
        unset($data['subject']);
        $data['template_id'] = 'tpl_123';
        // template_id varsa content zorunlu değil
        unset($data['content']);

        $result = $this->factory->fromArray($data);

        $this->assertEquals('tpl_123', $result['template_id']);
    }

    #[Test]
    public function from_array_throws_when_no_content_and_no_template(): void
    {
        $data = $this->validPayloadArray();
        unset($data['content']);

        $this->expectException(SwMailerProException::class);
        $this->expectExceptionMessageMatches('/content.*template_id/i');

        $this->factory->fromArray($data);
    }

    // ─── Helper Methods ─────────────────────────────────────────────

    #[Test]
    public function format_address_with_name(): void
    {
        $address = new Address('user@example.com', 'User Name');

        $result = $this->factory->formatAddress($address);

        $this->assertEquals(['email' => 'user@example.com', 'name' => 'User Name'], $result);
    }

    #[Test]
    public function format_address_without_name(): void
    {
        $address = new Address('user@example.com');

        $result = $this->factory->formatAddress($address);

        $this->assertEquals(['email' => 'user@example.com'], $result);
    }

    #[Test]
    public function format_addresses_maps_array(): void
    {
        $addresses = [
            new Address('a@example.com', 'Alice'),
            new Address('b@example.com'),
        ];

        $result = $this->factory->formatAddresses($addresses);

        $this->assertCount(2, $result);
        $this->assertEquals('a@example.com', $result[0]['email']);
        $this->assertEquals('Alice', $result[0]['name']);
        $this->assertEquals('b@example.com', $result[1]['email']);
        $this->assertArrayNotHasKey('name', $result[1]);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function makeEmail(): Email
    {
        return (new Email())
            ->from(new Address('sender@example.com', 'Sender'))
            ->to(new Address('recipient@example.com', 'Recipient'))
            ->subject('Test Subject');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayloadArray(): array
    {
        return [
            'from' => ['email' => 'sender@example.com', 'name' => 'Sender'],
            'personalizations' => [
                ['to' => [['email' => 'to@example.com']]],
            ],
            'subject' => 'Test Subject',
            'content' => [
                ['type' => 'text/html', 'value' => '<p>Test</p>'],
            ],
        ];
    }
}

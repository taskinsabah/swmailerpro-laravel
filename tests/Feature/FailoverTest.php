<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Exceptions\PayloadTooLargeException;
use SabahWeb\SwMailerPro\Exceptions\UnsupportedFeatureException;
use SabahWeb\SwMailerPro\Tests\TestCase;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mime\Email;

/**
 * MAIL_MAILER=failover altında yedeğe geçiş.
 *
 * Symfony'nin failover transport'u yalnızca TransportExceptionInterface yakalıyor,
 * bu paketin istisnaları da onu uygulamıyordu — yani yedek taşıyıcı tanımlamış bir
 * uygulamada gateway çökse bile yedeğe hiç geçilmiyor, mail düşüyordu.
 *
 * Ama her hata yedeğe geçmemeli: yerel retler "bu mesaj gönderilmemeli" diyor ve
 * yedeğe düşmek o kararın etrafından dolaşmak olur. Bu dosya iki tarafı da tutuyor.
 */
class FailoverTest extends TestCase
{
    private function failover(): FailoverTransport
    {
        return new FailoverTransport([
            app('mailer')->getSymfonyTransport(),
            new NullTransport(),
        ]);
    }

    private function mail(): Email
    {
        return (new Email())
            ->from('gonderen@example.com')
            ->to('dest@example.com')
            ->subject('Konu')
            ->text('gövde');
    }

    // ─── Gateway kaynaklı hatalar: yedeğe geçilmeli ──────────────────────

    #[Test]
    public function a_gateway_server_error_falls_over_to_the_backup(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'error' => ['code' => 'INTERNAL', 'message' => 'boom'],
        ], 500)]);

        // Yedek NullTransport olduğu için "geçildi" demek, istisnanın buradan
        // çıkmaması demek. Eskiden ApiException failover'ı delip geçiyordu.
        $this->failover()->send($this->mail());

        $this->assertTrue(true);
    }

    #[Test]
    public function an_unreachable_gateway_falls_over_to_the_backup(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->failover()->send($this->mail());

        $this->assertTrue(true);
    }

    #[Test]
    public function the_round_robin_debug_hook_exists(): void
    {
        // RoundRobinTransport yedeğe geçerken topladığı hataların üstünde
        // getDebug() çağırıyor. Metot olmasaydı failover yolu fatal ile biterdi,
        // yani arayüzü eklemek hatayı düzeltmek yerine büyütürdü.
        Http::fake(['*' => Http::response(['success' => false, 'error' => ['code' => 'X', 'message' => 'y']], 500)]);

        try {
            app('swmailerpro.client')->send([
                'from' => ['email' => 'gonderen@example.com'],
                'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
                'subject' => 'Konu',
                'content' => [['type' => 'text/plain', 'value' => 'gövde']],
            ]);
            $this->fail('bir istisna bekleniyordu');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(
                \Symfony\Component\Mailer\Exception\TransportExceptionInterface::class,
                $e,
            );
            $this->assertSame('', $e->getDebug());
            $e->appendDebug('satır');
            $this->assertSame('satır', $e->getDebug());
        }
    }

    // ─── Yerel retler: yedeğe geçilmemeli ────────────────────────────────

    #[Test]
    public function a_local_size_refusal_does_not_fall_over(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);
        config()->set('swmailerpro.limits.attachment_bytes', 4096);

        $mail = $this->mail();
        $mail->attach(str_repeat('A', 8192), 'buyuk.bin', 'application/octet-stream');

        // Yedeğe düşmek, gateway'in reddedeceği eki SMTP üzerinden yollamak olurdu.
        $this->expectException(PayloadTooLargeException::class);

        $this->failover()->send($mail);
    }

    #[Test]
    public function a_local_capability_refusal_does_not_fall_over(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $mail = $this->mail();
        $mail->getHeaders()->addTextHeader('X-SwMailerPro-Transactional', 'false');

        $this->expectException(UnsupportedFeatureException::class);

        $this->failover()->send($mail);
    }
}

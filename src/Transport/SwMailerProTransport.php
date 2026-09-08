<?php

namespace SabahWeb\SwMailerPro\Transport;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Events\EmailFailed;
use SabahWeb\SwMailerPro\Events\EmailSent;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;
use SabahWeb\SwMailerPro\Support\MeasuresBase64;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel mail transport adaptörü.
 *
 * Payload üretmez — PayloadFactory'ye delegate eder.
 * HTTP yapmaz — Client'a delegate eder.
 * Sorumluluğu: Email → payload çevirisi + event dispatch.
 */
class SwMailerProTransport extends AbstractTransport
{
    use MeasuresBase64;

    public function __construct(
        protected readonly SwMailerProClient $client,
        protected readonly PayloadFactory $payloadFactory,
        /** @var array{async?: bool, tracking?: array{open?: bool|null, click?: bool|null}} */
        protected readonly array $defaults = [],
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        // Payload starts empty so a failure while BUILDING it still reports the
        // same way a failed send does — pre-flight errors used to skip EmailFailed
        // entirely, which made a malformed template look like nothing happened.
        $payload = [];
        $async = (bool) ($this->defaults['async'] ?? false);

        try {
            $original = $message->getOriginalMessage();

            // A RawMessage is a MIME blob with no structure to read fields out of;
            // there is no payload we could honestly build from one.
            if (! $original instanceof Message) {
                throw new SwMailerProException('SwMailerPro: yalnızca Symfony Message tabanlı mailler gönderilebilir.');
            }

            $email = MessageConverter::toEmail($original);

            if (empty($email->getFrom()) || empty($email->getTo())) {
                throw new SwMailerProException('SwMailerPro: from ve to alanları zorunludur.');
            }

            $payload = $this->payloadFactory->fromEmail($email);
            $this->applyDefaults($payload);

            // One key per message, not per attempt. Symfony stamps a Message-ID on
            // every send, so a retry inside the client reuses this key and the
            // gateway replays its first answer instead of delivering twice — while a
            // queue worker retrying a failed job builds a new message, gets a new id,
            // and is correctly sent as the new mail it is. It also keeps the gateway
            // from falling back to content-fingerprint dedup, which silently swallows
            // a second, legitimately identical message within its TTL.
            $idempotencyKey = $this->idempotencyKeyFor($message);

            $response = $async
                ? $this->client->sendAsync($payload, $idempotencyKey)
                : $this->client->send($payload, $idempotencyKey);
        } catch (\Throwable $e) {
            try {
                Event::dispatch(new EmailFailed(
                    payload: $this->forEvent($payload),
                    exception: $e,
                ));
            } catch (\Throwable $listenerFailure) {
                // Gönderim zaten başarısız. Patlayan bir dinleyicinin istisnası
                // buradan çıkarsa asıl sebebin YERİNE geçiyordu — üstelik onu
                // previous olarak da taşımadan, yani gerçek hata tamamen
                // kayboluyordu. Başarı yolunda bu koruma zaten vardı.
                App::make(ExceptionHandler::class)->report($listenerFailure);
            }

            throw $e;
        }

        $this->recordSuccess($message, $payload, $response, $async);
    }

    /**
     * Gönderim başarılı; bundan sonrası defter tutma.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $response
     */
    protected function recordSuccess(SentMessage $message, array $payload, array $response, bool $async): void
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $requestId = $response['request_id'] ?? null;

        // Laravel's own SentMessage carries this onward, so an application can tie
        // its log line to the gateway's record without reading our events at all.
        $providerMessageId = $data['provider_message_id'] ?? null;
        if (is_string($providerMessageId) && $providerMessageId !== '') {
            $message->setMessageId($providerMessageId);
        }

        $suppressed = is_array($data['suppressed_recipients'] ?? null)
            ? array_values(array_filter($data['suppressed_recipients'], 'is_string'))
            : [];

        try {
            Event::dispatch(new EmailSent(
                payload: $this->forEvent($payload),
                response: $response,
                requestId: is_string($requestId) ? $requestId : null,
                queued: $async,
                suppressedRecipients: $suppressed,
            ));
        } catch (\Throwable $e) {
            // The mail has already left. A listener that throws must not be turned
            // into a send failure: Symfony would report the send as failed and the
            // queue would retry a message the gateway has already delivered.
            App::make(ExceptionHandler::class)->report($e);
        }
    }

    /**
     * Event'e giden payload: ek içerikleri çıkarılmış hâli.
     *
     * Kuyruğa alınmış bir dinleyici event'i serialize eder, yani base64 ekler
     * `jobs` ve `failed_jobs` tablolarına satır olarak yazılır — 10 MB'lık bir ek
     * ~14 MB base64 demek ve bu bir MySQL paket sınırına çarpar. Dinleyicinin
     * ihtiyacı olan ekin kendisi değil, ne olduğu: isim, tip, boyut yerinde kalır.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function forEvent(array $payload): array
    {
        if (! is_array($payload['attachments'] ?? null)) {
            return $payload;
        }

        foreach ($payload['attachments'] as $i => $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['content'] ?? null)) {
                continue;
            }

            $encoded = $attachment['content'];
            $payload['attachments'][$i]['content'] = null;
            $payload['attachments'][$i]['size_bytes'] = $this->decodedSize($encoded);
        }

        return $payload;
    }

    /**
     * Bu mesajın idempotency anahtarı.
     *
     * Message-ID okunabiliyorsa o kullanılır: gateway loglarıyla mesajı
     * eşleştirmeyi de mümkün kılar. Okunamazsa rastgele bir anahtar üretilir —
     * anahtarsız göndermek, tekrar denemede aynı mailin iki kez gitmesi demek.
     */
    protected function idempotencyKeyFor(SentMessage $message): string
    {
        try {
            $messageId = $message->getMessageId();
        } catch (\Throwable) {
            $messageId = '';
        }

        return $messageId !== '' ? $messageId : (string) Str::uuid();
    }

    /**
     * Config defaults'larını payload'a uygula.
     *
     * @param array<string, mixed> &$payload
     */
    protected function applyDefaults(array &$payload): void
    {
        $trackingOpen = $this->defaults['tracking']['open'] ?? null;
        $trackingClick = $this->defaults['tracking']['click'] ?? null;

        if ($trackingOpen !== null || $trackingClick !== null) {
            $tracking = $payload['tracking_settings'] ?? [];

            if ($trackingOpen !== null && !isset($tracking['open_tracking'])) {
                $tracking['open_tracking'] = ['enable' => (bool) $trackingOpen];
            }

            if ($trackingClick !== null && !isset($tracking['click_tracking'])) {
                $tracking['click_tracking'] = ['enable' => (bool) $trackingClick];
            }

            if (!empty($tracking)) {
                $payload['tracking_settings'] = $tracking;
            }
        }
    }

    public function __toString(): string
    {
        return 'swmailerpro';
    }
}

<?php

namespace SabahWeb\SwMailerPro\Transport;

use Illuminate\Support\Str;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Events\EmailFailed;
use SabahWeb\SwMailerPro\Events\EmailSent;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;
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
        $original = $message->getOriginalMessage();

        // A RawMessage is a MIME blob with no structure to read fields out of;
        // there is no payload we could honestly build from one.
        if (! $original instanceof Message) {
            throw new \RuntimeException('SwMailerPro: yalnızca Symfony Message tabanlı mailler gönderilebilir.');
        }

        $email = MessageConverter::toEmail($original);

        $from = $email->getFrom();
        $to = $email->getTo();

        if (empty($from) || empty($to)) {
            throw new \RuntimeException('SwMailerPro: from ve to alanları zorunludur.');
        }

        $payload = $this->payloadFactory->fromEmail($email);

        // Defaults'dan tracking ayarları merge
        $this->applyDefaults($payload);

        // One key per message, not per attempt. Symfony stamps a Message-ID on
        // every send (generating one if the app did not), so a retry inside the
        // client reuses this key and the gateway replays its first answer instead
        // of delivering twice — while a queue worker retrying a failed job builds
        // a new message, gets a new id, and is correctly sent as a new mail.
        $idempotencyKey = $this->idempotencyKeyFor($message);

        try {
            $async = $this->defaults['async'] ?? false;
            $response = $async
                ? $this->client->sendAsync($payload, $idempotencyKey)
                : $this->client->send($payload, $idempotencyKey);

            $requestId = $response['request_id'] ?? null;

            event(new EmailSent(
                payload: $payload,
                response: $response,
                requestId: is_string($requestId) ? $requestId : null,
                queued: (bool) $async,
            ));
        } catch (\Throwable $e) {
            event(new EmailFailed(
                payload: $payload,
                exception: $e,
            ));

            throw $e;
        }
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

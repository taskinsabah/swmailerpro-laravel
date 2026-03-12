<?php

namespace SabahWeb\SwMailerPro\Transport;

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
        $originalMessage = $message->getOriginalMessage();

        if (! $originalMessage instanceof Message) {
            throw new \RuntimeException('SwMailerPro: Desteklenmeyen mesaj tipi — Message instance bekleniyor.');
        }

        $email = MessageConverter::toEmail($originalMessage);

        $from = $email->getFrom();
        $to = $email->getTo();

        if (empty($from) || empty($to)) {
            throw new \RuntimeException('SwMailerPro: from ve to alanları zorunludur.');
        }

        $payload = $this->payloadFactory->fromEmail($email);

        // Defaults'dan tracking ayarları merge
        $this->applyDefaults($payload);

        try {
            $async = $this->defaults['async'] ?? false;
            $response = $async
                ? $this->client->sendAsync($payload)
                : $this->client->send($payload);

            $requestId = $response['request_id'] ?? null;

            event(new EmailSent(
                payload: $payload,
                response: $response,
                requestId: $requestId,
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

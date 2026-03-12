<?php

namespace SabahWeb\SwMailerPro\Payload;

use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Tek payload üretim noktası.
 *
 * Transport (fromEmail) ve Facade/direct kullanım (fromArray) aynı factory'yi
 * kullanır. V2'de Notification Channel da bu factory'ye bağlanacak.
 * Bu sayede payload standardı tek yerde tutulur, drift riski sıfırdır.
 */
class PayloadFactory
{
    /**
     * Symfony Email nesnesinden SwMailerPro API payload'ı üretir.
     *
     * laravel-integration.md buildPayload() kodunun birebir portu.
     *
     * @return array<string, mixed>
     */
    public function fromEmail(Email $email): array
    {
        $payload = [
            'from' => $this->formatAddress($email->getFrom()[0]),
            'subject' => $email->getSubject(),
            'personalizations' => [
                [
                    'to' => $this->formatAddresses($email->getTo()),
                ],
            ],
            'content' => [],
        ];

        // CC
        if ($cc = $email->getCc()) {
            $payload['personalizations'][0]['cc'] = $this->formatAddresses($cc);
        }

        // BCC
        if ($bcc = $email->getBcc()) {
            $payload['personalizations'][0]['bcc'] = $this->formatAddresses($bcc);
        }

        // Reply-To
        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = $this->formatAddress($replyTo[0]);
        }

        // Content — Text
        if ($textBody = $email->getTextBody()) {
            $payload['content'][] = [
                'type' => 'text/plain',
                'value' => $textBody,
            ];
        }

        // Content — HTML
        if ($htmlBody = $email->getHtmlBody()) {
            $payload['content'][] = [
                'type' => 'text/html',
                'value' => $htmlBody,
            ];
        }

        // Attachments
        $attachments = $email->getAttachments();
        if (count($attachments) > 0) {
            $payload['attachments'] = [];
            foreach ($attachments as $attachment) {
                $payload['attachments'][] = [
                    'content' => base64_encode($attachment->getBody()),
                    'filename' => $attachment->getFilename() ?? 'attachment',
                    'type' => $this->getAttachmentMimeType($attachment),
                ];
            }
        }

        // --- Custom Header'lar ---
        $headers = $email->getHeaders();

        // Template desteği
        if ($headers->has('X-SwMailerPro-Template')) {
            $templateHeader = $headers->get('X-SwMailerPro-Template');
            if ($templateHeader !== null) {
                $payload['template_id'] = $templateHeader->getBodyAsString();
                $headers->remove('X-SwMailerPro-Template');
            }

            if ($headers->has('X-SwMailerPro-Data')) {
                $dataHeader = $headers->get('X-SwMailerPro-Data');
                if ($dataHeader !== null) {
                    $json = $dataHeader->getBodyAsString();
                    try {
                        $payload['template_data'] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        throw new SwMailerProException(
                            "SwMailerPro: template_data geçersiz JSON — {$e->getMessage()}"
                        );
                    }
                    $headers->remove('X-SwMailerPro-Data');
                }
            }

            // Template kullanılıyorsa content opsiyonel
            if (empty($payload['content'])) {
                unset($payload['content']);
            }
        }

        // Campaign ID
        if ($headers->has('X-SwMailerPro-Campaign')) {
            $campaignHeader = $headers->get('X-SwMailerPro-Campaign');
            if ($campaignHeader !== null) {
                $payload['campaign_id'] = $campaignHeader->getBodyAsString();
                $headers->remove('X-SwMailerPro-Campaign');
            }
        }

        // Transactional flag
        if ($headers->has('X-SwMailerPro-Transactional')) {
            $transactionalHeader = $headers->get('X-SwMailerPro-Transactional');
            if ($transactionalHeader !== null) {
                $payload['transactional'] = filter_var(
                    $transactionalHeader->getBodyAsString(),
                    FILTER_VALIDATE_BOOLEAN
                );
                $headers->remove('X-SwMailerPro-Transactional');
            }
        }

        return $payload;
    }

    /**
     * Raw array'den SwMailerPro API payload'ı üretir.
     *
     * Facade ve direct kullanım için. Zorunlu alan doğrulaması yapar —
     * sessizce bozuk payload üretilmesini engeller.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     *
     * @throws SwMailerProException Zorunlu alanlar eksikse
     */
    public function fromArray(array $data): array
    {
        // from zorunlu
        if (empty($data['from']) || !isset($data['from']['email'])) {
            throw new SwMailerProException(
                'SwMailerPro: from alanı zorunludur ve email key içermelidir.'
            );
        }

        // En az bir recipient zorunlu
        $hasRecipient = false;
        if (!empty($data['personalizations']) && is_array($data['personalizations'])) {
            foreach ($data['personalizations'] as $p) {
                if (!empty($p['to']) && is_array($p['to'])) {
                    $hasRecipient = true;
                    break;
                }
            }
        }
        if (!$hasRecipient) {
            throw new SwMailerProException(
                'SwMailerPro: En az bir recipient (personalizations[].to) zorunludur.'
            );
        }

        // subject veya template_id zorunlu
        $hasSubject = !empty($data['subject']);
        $hasTemplate = !empty($data['template_id']);
        if (!$hasSubject && !$hasTemplate) {
            throw new SwMailerProException(
                'SwMailerPro: subject veya template_id alanlarından en az biri zorunludur.'
            );
        }

        // content veya template_id zorunlu
        $hasContent = !empty($data['content']) && is_array($data['content']);
        if (!$hasContent && !$hasTemplate) {
            throw new SwMailerProException(
                'SwMailerPro: content veya template_id alanlarından en az biri zorunludur.'
            );
        }

        return $data;
    }

    /**
     * @return array{email: string, name?: string}
     */
    public function formatAddress(Address $address): array
    {
        $formatted = ['email' => $address->getAddress()];

        if ($name = $address->getName()) {
            $formatted['name'] = $name;
        }

        return $formatted;
    }

    /**
     * @param Address[] $addresses
     * @return array<int, array{email: string, name?: string}>
     */
    public function formatAddresses(array $addresses): array
    {
        return array_map(fn (Address $addr) => $this->formatAddress($addr), $addresses);
    }

    public function getAttachmentMimeType(DataPart $attachment): string
    {
        try {
            $type = $attachment->getMediaType();
            $subtype = $attachment->getMediaSubtype();
            if ($type && $subtype) {
                return "{$type}/{$subtype}";
            }
        } catch (\Throwable) {
            // Bazı attachment kaynakları (fromData vb.) MIME bilgisi taşımayabilir
        }

        return 'application/octet-stream';
    }
}

<?php

namespace SabahWeb\SwMailerPro\Payload;

use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use SabahWeb\SwMailerPro\Exceptions\UnsupportedFeatureException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\Mime\Header\UnstructuredHeader;
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
            'personalizations' => [
                [
                    'to' => $this->formatAddresses($email->getTo()),
                ],
            ],
            'content' => [],
        ];

        // Sent only when there is one. The gateway's schema accepts a string or
        // the absent key — a null subject is a 400, which is what a template
        // send (legitimately subject-less here) used to produce.
        $subject = $email->getSubject();
        if ($subject !== null && $subject !== '') {
            $payload['subject'] = $subject;
        }

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
                $entry = [
                    'content' => base64_encode($attachment->getBody()),
                    'filename' => $attachment->getFilename() ?? 'attachment',
                    'type' => $this->getAttachmentMimeType($attachment),
                ];

                // An embedded image only renders as an image if the gateway is told
                // it is inline and which cid the HTML points at. Dropping these two
                // fields turned every <img src="cid:..."> into a broken image and a
                // stray file attachment.
                $disposition = $attachment->getDisposition();
                if ($disposition === 'inline' || $disposition === 'attachment') {
                    $entry['disposition'] = $disposition;
                }

                // Laravel's $message->embed() stamps a cid and puts it in the HTML,
                // so it is there to copy. Symfony's own Email::embed() instead lets
                // the HTML say cid:<name> and resolves it at render time — which we
                // bypass, so the filename has to stand in as the id.
                if ($attachment->hasContentId()) {
                    $entry['content_id'] = $attachment->getContentId();
                } elseif ($disposition === 'inline' && $attachment->getFilename() !== null) {
                    $entry['content_id'] = $attachment->getFilename();
                }

                $payload['attachments'][] = $entry;
            }
        }

        // --- Custom Header'lar ---
        // Kontrol başlıkları aşağıda remove() ile tüketiliyor — ama çağıranın
        // nesnesi üzerinde değil. Symfony gönderdiği mesajı SentMessage içinde
        // dinleyicilere devrediyor; orijinali tüketmek, uygulamanın
        // MessageSent dinleyicisine template/campaign başlıkları silinmiş bir
        // mesaj gitmesi ve aynı Email'i ikinci kez göndermenin sessizce
        // template'siz kalması demekti. Kopya üzerinde çalışıyoruz.
        $headers = clone $email->getHeaders();

        // Template desteği
        $templateId = $this->headerValue($headers, 'X-SwMailerPro-Template');
        if ($templateId !== null) {
            $payload['template_id'] = $templateId;
            $headers->remove('X-SwMailerPro-Template');
        }

        // Template başlığından BAĞIMSIZ okunuyor. Eskiden yalnızca template
        // varken tüketiliyordu; tek başına bırakılan bir Data başlığı aşağıdaki
        // customHeaders'a düşüp gerçek bir mesaj başlığı olarak sağlayıcıya
        // gidiyordu — yani template değişkenleri teslim edilen mailin
        // başlıklarında görünüyordu.
        $json = $this->headerValue($headers, 'X-SwMailerPro-Data');
        if ($json !== null) {
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new SwMailerProException(
                    "SwMailerPro: template_data geçersiz JSON — {$e->getMessage()}"
                );
            }

            // Template yoksa dolduracağı bir şey de yok; gateway'in de işine
            // yaramaz. Tüketilir, iletilmez. Bozuk JSON yine de hata verir:
            // sessizce yutulan bozuk bir kontrol başlığı bu hatanın geri
            // dönüş yolu.
            if ($templateId !== null) {
                $payload['template_data'] = $decoded;
            }

            $headers->remove('X-SwMailerPro-Data');
        }

        // Template kullanılıyorsa content opsiyonel
        if ($templateId !== null && empty($payload['content'])) {
            unset($payload['content']);
        }

        // Campaign ID
        $campaignId = $this->headerValue($headers, 'X-SwMailerPro-Campaign');
        if ($campaignId !== null) {
            $payload['campaign_id'] = $campaignId;
            $headers->remove('X-SwMailerPro-Campaign');
        }

        // Transactional flag
        $transactional = $this->headerValue($headers, 'X-SwMailerPro-Transactional');
        if ($transactional !== null) {
            // filter_var yalnızca "1", "true", "on", "yes" için true döner. Geri kalan
            // her şey — "false" ve "0" kadar, yazım hatasıyla girilmiş "maybe" de —
            // non-transactional gönderim istemek demektir ve burada biter.
            if (! filter_var($transactional, FILTER_VALIDATE_BOOLEAN)) {
                throw UnsupportedFeatureException::nonTransactional(
                    'X-SwMailerPro-Transactional',
                    $transactional,
                );
            }

            $payload['transactional'] = true;
            $headers->remove('X-SwMailerPro-Transactional');
        }

        // Whatever the application set itself — List-Unsubscribe, X-Priority, a
        // correlation id — travels with the message. Read last, so the
        // X-SwMailerPro-* control headers above have already been removed and
        // never leak to the provider.
        $custom = $this->customHeaders($headers);
        if ($custom !== []) {
            $payload['headers'] = $custom;
        }

        return $payload;
    }

    /**
     * Headers the gateway derives from the payload itself. Forwarding them
     * would duplicate or fight what it sets from from/personalizations/content.
     */
    private const DERIVED_HEADERS = [
        'from',
        'to',
        'cc',
        'bcc',
        'reply-to',
        'sender',
        'return-path',
        'subject',
        'date',
        'message-id',
        'mime-version',
        'content-type',
        'content-transfer-encoding',
        'content-disposition',
    ];

    /**
     * Bir başlığın gövdesi; başlık yoksa null.
     */
    protected function headerValue(Headers $headers, string $name): ?string
    {
        $header = $headers->get($name);

        return $header === null ? null : $this->headerBody($header);
    }

    /**
     * Bir başlığın gateway'e gidecek gövdesi: ham UTF-8, satır sonsuz.
     *
     * Gateway değeri JSON'da alıp sağlayıcıya olduğu gibi veriyor
     * (mailchannels.provider.ts `body.headers = payload.headers`,
     * smtp2go.provider.ts `custom_headers.push({ header, value })`) ve MIME
     * kodlamasını sağlayıcı yapıyor — gateway hiçbir yerde kodlama yapmıyor.
     * Bu yüzden getBodyAsString()'in ürettiği =?utf-8?Q?..?= biçimi burada
     * yanlıştı: sağlayıcı onu bir kez daha kodlayınca alıcı "Ayşe" yerine
     * "=?utf-8?Q?Ay=C5=9Fe?=" görüyordu. Subject ve display name zaten ham
     * gidiyor; başlık da onlarla aynı yoldan gitmeli.
     */
    protected function headerBody(HeaderInterface $header): string
    {
        // ParameterizedHeader ÖNCE sorulmalı: UnstructuredHeader'ı genişletiyor
        // ve getValue() ondan yalnızca temel değeri döndürür — parametreler
        // düşer (attachment; filename*=... -> attachment). Parametrelerin
        // RFC 2231 karşılığı ham metnin değil telin biçimidir; bu yüzden
        // burada gövdenin tamamı alınır.
        if ($header instanceof ParameterizedHeader) {
            return $this->assertNoLineBreak($header->getName(), $this->unfold($header->getBodyAsString()));
        }

        // Düz metin başlık: değer ham gider. getValue() katlama da yapmaz;
        // getBodyAsString() uzun bir Türkçe değeri satırlara bölüp aralarına
        // CRLF koyuyordu, gateway de o CRLF yüzünden isteği 400'lüyordu.
        if ($header instanceof UnstructuredHeader) {
            return $this->assertNoLineBreak($header->getName(), $header->getValue());
        }

        // Mailbox/Date/Id/Path başlıkları: gövdeleri zaten string değil
        // (Address, DateTimeImmutable, string[]); tek doğru karşılıkları tel biçimi.
        return $this->assertNoLineBreak($header->getName(), $this->unfold($header->getBodyAsString()));
    }

    /**
     * RFC 5322 katlamasını geri alır: CRLF + boşluk, o boşluğa iner. Katlanmış
     * bir başlık katlanmamış hâliyle aynı anlama gelir, dolayısıyla aşağıdaki
     * kontrol yalnızca gerçek satır sonlarına takılır.
     */
    protected function unfold(string $value): string
    {
        return preg_replace("/\r\n([ \t])/", '$1', $value) ?? $value;
    }

    /**
     * Satır sonu taşıyan bir başlığı reddeder — düzeltmez.
     *
     * Bir CR ya da LF, sağlayıcının kurduğu mesaja yeni başlık yazma yoludur:
     * "ok\r\nBcc: x@y" tek bir X-Note değil, bir X-Note ile bir Bcc'dir.
     * Ayıklamak ya da boşluğa çevirmek, uygulamanın yazmadığı bir başlığı
     * sessizce teslim etmek olurdu. guardSize ve guardCapabilities'teki karar
     * burada da geçerli: gateway'in zaten reddedeceği (validators/schemas.ts
     * personalizationHeadersSchema) bir isteği ağa hiç çıkarmadan, sebebini
     * söyleyerek bitiriyoruz.
     */
    protected function assertNoLineBreak(string $name, string $value): string
    {
        if (! preg_match("/[\r\n]/", $value)) {
            return $value;
        }

        throw new SwMailerProException(sprintf(
            'SwMailerPro: "%s" başlığı satır sonu içeriyor; bir CRLF sağlayıcının '
            . 'mesajına yeni bir başlık yazar. İstek gönderilmedi.',
            str_replace(["\r", "\n"], ['\r', '\n'], $name),
        ));
    }

    /**
     * Gateway'e gidecek başlık haritası.
     *
     * Anahtar tipi int|string, çünkü PHP sayısal görünen bir dizi anahtarını
     * int'e çevirir: "123" adlı bir başlık $custom[123] olur. RFC böyle bir adı
     * yasaklamıyor, pratikte de kimse göndermiyor, ve payload JSON'a giderken
     * anahtar yeniden string olduğu için gateway tarafında fark etmiyor —
     * ama burada string demek yalan olurdu.
     *
     * @return array<int|string, string>
     */
    protected function customHeaders(Headers $headers): array
    {
        $custom = [];

        foreach ($headers->all() as $header) {
            $name = strtolower($header->getName());

            if (in_array($name, self::DERIVED_HEADERS, true)) {
                continue;
            }

            // Kontrol başlıkları yukarıda tüketiliyor; bu, biri gözden kaçarsa
            // sağlayıcıya gitmesini engelleyen ikinci kilit.
            if (str_starts_with($name, 'x-swmailerpro-')) {
                continue;
            }

            $rawName = $header->getName();

            // Symfony başlık adını doğrulamıyor: adının içine CRLF konmuş bir
            // başlık da kabul ediliyor, ve ad da değer kadar iyi bir enjeksiyon yolu.
            $this->assertNoLineBreak($rawName, $rawName);

            $custom[$rawName] = $this->headerBody($header);
        }

        return $custom;
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
        // Desteklenmeyen bir yetenek isteniyorsa eksik alan aramanın anlamı yok;
        // çağıranın duyması gereken hata bu.
        UnsupportedFeatureException::guardPayload($data);

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

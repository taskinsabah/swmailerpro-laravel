<?php

namespace SabahWeb\SwMailerPro\Events;


class EmailSent
{

    public function __construct(
        /** @var array<string, mixed> Gönderilen payload */
        public readonly array $payload,
        /** @var array<string, mixed> API yanıtı */
        public readonly array $response,
        /** @var string|null Gateway request ID */
        public readonly ?string $requestId = null,
        /**
         * @var bool Gateway mesajı kuyruğa aldıysa true — teslim edildiği anlamına
         *           GELMEZ. /send-async 202 döndüğünde mail henüz gitmemiştir;
         *           teslimat webhook'la bildirilir. Bunu ayırt edemeyen bir
         *           dinleyici "gönderildi" diye yanlış rapor üretir.
         */
        public readonly bool $queued = false,
        /**
         * @var list<string> Gateway'in engelli listesi yüzünden çıkarılan alıcılar.
         *                   Yalnızca KISMİ eleme burayı doldurur: mesaj kalan
         *                   alıcılara gitmiştir. Alıcıların tamamı elenirse gateway
         *                   422 döner (ALL_RECIPIENTS_SUPPRESSED ya da
         *                   RESERVED_DOMAIN_RECIPIENTS), yani bu event hiç
         *                   yayınlanmaz — o durumun işareti EmailFailed ve
         *                   ApiException'dır.
         */
        public readonly array $suppressedRecipients = [],
    ) {
    }
}

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
         *                   Tümü çıkarıldıysa bu mail kimseye gitmemiştir — yanıt
         *                   yine de 200 döner, dolayısıyla tek işaret budur.
         */
        public readonly array $suppressedRecipients = [],
    ) {
    }
}

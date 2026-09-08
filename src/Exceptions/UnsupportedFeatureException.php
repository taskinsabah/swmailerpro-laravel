<?php

namespace SabahWeb\SwMailerPro\Exceptions;

/**
 * Gateway'in tanıdığı ama bu istemcinin bilerek üretmediği bir yetenek istendi;
 * istek ağa hiç çıkmadı.
 *
 * Bugünkü tek örneği non-transactional gönderim. Gateway bunu DKIM imzası şartına
 * bağlıyor: transactional false ise ya gövde seviyesinde ya da her personalization
 * içinde bir dkim_selector arıyor. Bu paket hiçbir yolda dkim alanı üretmediği için,
 * transport üzerinden çıkan her non-transactional mail garantili bir 400'dü.
 *
 * Ham payload yolunda çağıran kendi dkim_selector'ını yazarsa gateway bunu bugün
 * kabul ediyor — yine de reddediyoruz, ve sebep teknik değil sahiplik. Hangi alan
 * adı adına imza atıldığı mesajın bir alanı değil, gönderim altyapısının ayarıdır.
 * Gateway bu alanları çağırandan alıp sağlayıcıya aynen ilettiği için, geçmesine
 * izin vermek bir Laravel uygulamasının istediği dkim_domain'i iddia edebilmesi
 * demek olurdu. Karar, o çözümleme gateway'de tenant'a bağlanana kadar geçerli.
 */
class UnsupportedFeatureException extends SwMailerProException
{
    /**
     * Payload'ı, bu istemcinin desteklemediği bir yetenek için tarar.
     *
     * Tek kural tek yerde dursun diye buraya kondu: hem PayloadFactory (erken,
     * çağırana yakın), hem SwMailerProClient (son kapı, ham payload dahil her
     * yolu kapsar) aynı soruyu soruyor.
     *
     * Gateway'in alanı katı bir boolean — z.boolean(), varsayılanı true — ve
     * varsayılan yalnızca alan hiç yokken devreye giriyor. Dolayısıyla true
     * dışındaki her değer ya doğrudan non-transactional istemek, ya da tip
     * hatasıyla 400 almak demek. İkisi de burada bitiyor.
     *
     * @param array<string, mixed> $payload
     *
     * @throws self
     */
    public static function guardPayload(array $payload): void
    {
        if (array_key_exists('transactional', $payload) && $payload['transactional'] !== true) {
            throw self::nonTransactional('transactional', $payload['transactional']);
        }
    }

    /**
     * Reddedilen değeri de söyleyen mesaj — hangi kapıdan girdiği ve ne yazıldığı
     * olmadan çağıran, kendi payload'ında neyi düzelteceğini bilemiyor.
     *
     * var_export, json_encode'un aksine false ve 0 için de okunabilir bir karşılık
     * üretir; json_encode(0) "0" döndürür ve o da PHP'de falsy olduğu için bir
     * yedek dala düşer.
     */
    public static function nonTransactional(string $field, mixed $value): self
    {
        return new self(sprintf(
            'SwMailerPro: non-transactional gönderim bu istemciden desteklenmiyor (%s: %s). '
            . 'Gateway non-transactional maili DKIM imzası şartına bağlıyor; DKIM kimliği mesajın '
            . 'değil gönderim altyapısının ayarı olduğu için bu paket dkim alanı üretmez. '
            . 'İstek gönderilmedi. Gateway gönderim alan adını tenant üzerinden çözdüğünde açılacak.',
            $field,
            var_export($value, true),
        ));
    }
}

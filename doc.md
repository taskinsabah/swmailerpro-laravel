# SwMailerPro Laravel — Entegrasyon ve Deploy Rehberi

Bu rehber, `sabahweb/swmailerpro-laravel` paketini mevcut bir Laravel 12 veya 13 projesine adım adım kurmayı ve production ortamında aktif etmeyi kapsar.

---

## Ön Koşullar

| Gereksinim | Minimum |
|---|---|
| PHP | 8.2+ |
| Laravel | 12.x |
| `symfony/mailer` + `symfony/mime` | 7.4 (ya da 8.x) |
| SwMailerPro Gateway | Erişilebilir URL + Tenant API Key |
| Composer | 2.x |

> **Laravel 12 için önemli.** Laravel 12'nin kendi tabanı `symfony/mailer: ^7.2.0`,
> bu paket ise `^7.4 || ^8.0` istiyor. `composer.lock` dosyanız 7.2/7.3'te takılıysa
> kurulum çözülemez ve Composer suçlu olarak bu paketi gösterir. Symfony'yi
> yükseltmek yeter:
>
> ```bash
> composer update symfony/mailer symfony/mime --with-dependencies
> ```
>
> Laravel 13 zaten `^7.4 || ^8.0` istiyor.

---

## 1. Paketi Yükle

### A) Packagist üzerinden (normal yol)

```bash
composer require sabahweb/swmailerpro-laravel
```

Sürüm kısıtını yazmayın; Composer yayımdaki sürüme bakıp `composer.json`'a doğru
kısıtı (`^1.0` gibi) kendisi yazar.

### B) GitHub reposu üzerinden (Packagist'te bulunamıyorsa)

`Could not find package sabahweb/swmailerpro-laravel` hatası paketin o an
Packagist'te yayımlanmadığını söyler. Bir fork'tan ya da özel bir aynadan kurarken
de bu yol geçerlidir. Depoyu Composer'a tanıtın:

```bash
composer config repositories.swmailerpro vcs https://github.com/taskinsabah/swmailerpro-laravel
```

Bu komut `composer.json`'a şunu ekler:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/taskinsabah/swmailerpro-laravel"
        }
    ]
}
```

Sonra kurulumu yapın:

```bash
composer require sabahweb/swmailerpro-laravel
```

Paket Packagist'e yayımlandığında `repositories` girdisini silip A yoluna
dönebilirsiniz.

> **Windows uyarısı — `^` kısıtını komut satırına yazmayın.** Composer Windows'ta
> bir `.bat` sarmalayıcı üzerinden çalışır (`composer.bat` → `php composer.phar %*`)
> ve `cmd.exe` bu genişletmede `^` karakterini yutar. Tırnak bunu kurtarmaz:
> PowerShell'de `pkg:^1.0`, `"pkg:^1.0"` ve `'pkg:^1.0'` üçü de Composer'a
> `pkg:1.0` olarak ulaşır, yani kısıt sessizce tam sürüm pinine döner
> (`The "1.0" constraint ... appears too strict` uyarısı bunun belirtisidir).
> Kısıtı hiç yazmayın; yazmanız gerekiyorsa caret içermeyen bir biçim kullanın:
> `composer require "sabahweb/swmailerpro-laravel:1.0.*"`.

> **Not:** Paket Laravel auto-discover destekler — ServiceProvider ve Facade kayıtlarını elle yapmanız gerekmez.

---

## 2. Config Dosyasını Publish Et

```bash
php artisan vendor:publish --tag=swmailerpro-config
```

Bu komut `config/swmailerpro.php` dosyasını oluşturur. İçeriği:

```php
return [
    'url' => env('SWMAILERPRO_URL', 'http://localhost:3000'),
    'key' => env('SWMAILERPRO_KEY', ''),

    'transport' => [
        'timeout'         => env('SWMAILERPRO_TRANSPORT_TIMEOUT', 30),
        'connect_timeout' => env('SWMAILERPRO_TRANSPORT_CONNECT_TIMEOUT', 10),
        'retry'           => ['times' => 2, 'sleep' => 200],
        'max_retry_after' => 5,
    ],

    'client' => [
        'timeout'         => env('SWMAILERPRO_CLIENT_TIMEOUT', 30),
        'connect_timeout' => env('SWMAILERPRO_CLIENT_CONNECT_TIMEOUT', 10),
        'retry'           => ['times' => 2, 'sleep' => 200],
        'max_retry_after' => 5,
    ],

    'idempotency' => env('SWMAILERPRO_IDEMPOTENCY', true),

    'limits' => [
        'attachments'             => 10,
        'attachment_bytes'        => 10 * 1024 * 1024,
        'attachments_total_bytes' => 15 * 1024 * 1024,
        'personalizations'        => 1000,
        'body_bytes'              => 20 * 1024 * 1024,
        // Sadece async yolda: gateway kuyruğa alınan payload'a çok daha dar bir
        // tavan uyguluyor (QUEUE_MAX_PAYLOAD_BYTES, varsayılan 2 MiB).
        'async_payload_bytes'     => 2 * 1024 * 1024,
    ],

    'defaults' => [
        // Boşsa mail.from.address'e düşer — taze bir Laravel kurulumunda o adres
        // hello@example.com'dur ve gateway'de hiçbir tenant'a karşılık gelmez.
        'from_email' => env('SWMAILERPRO_FROM_EMAIL', ''),
        'async'    => false,
        'tracking' => ['open' => null, 'click' => null],
    ],
];
```

---

## 3. Environment Değişkenlerini Ayarla

`.env` dosyanıza ekleyin:

```env
# ── SwMailerPro Gateway ──────────────────────────
SWMAILERPRO_URL=https://mail.yourdomain.com
SWMAILERPRO_KEY=your-tenant-api-key

# Gönderici adresi. Alan adı gateway'de tenant'ınıza KAYITLI olmalı: gateway
# tenant'ı from adresinin alan adından çözüyor. Boş bırakırsanız mail.from.address
# kullanılır ve taze bir Laravel kurulumunda o değer hello@example.com'dur —
# gateway o isteği 403 TENANT_NOT_FOUND ile reddeder.
SWMAILERPRO_FROM_EMAIL=noreply@yourdomain.com

# ── Laravel Mail Driver ──────────────────────────
MAIL_MAILER=swmailerpro
```

> **Güvenlik:** `SWMAILERPRO_KEY` değeri asla koda gömülmemelidir. Sadece `.env` veya environment secrets (CI/CD, server env) üzerinden sağlanmalıdır.

---

## 4. Mail Driver Kaydı

`config/mail.php` → `mailers` dizisine ekleyin:

```php
'mailers' => [
    // ... mevcut mailer'lar

    'swmailerpro' => [
        'transport' => 'swmailerpro',
    ],
],
```

---

## 5. Gateway Bağlantısını Doğrula

```bash
php artisan swmailerpro:health
```

Başarılı çıktı:

```
SwMailerPro gateway'e bağlanılıyor...

  Durum: healthy
  Uptime: 86400s

+──────────────+────────+─────────────────+────────────+
| Provider     | Durum  | Circuit Breaker | Hata Sayısı|
+──────────────+────────+─────────────────+────────────+
| mailchannels | active | closed          | 0          |
+──────────────+────────+─────────────────+────────────+

Gateway sağlıklı.
```

---

## 6. Payload'ı Doğrula (dry-run)

```bash
php artisan swmailerpro:test --to=siz@yourdomain.com
```

**Bu komut mail GÖNDERMEZ.** Gateway'in `/api/v1/email/send-test` ucunu çağırır;
orası payload'ı doğrular, template'i render eder ve sonucu döndürür — hiçbir
sağlayıcıya bir şey verilmez, hiçbir kutuya bir şey düşmez. Komut bunu çıktısında
da söyler: "Payload doğrulandı. Bu bir dry-run: mail GÖNDERİLMEDİ."

Uçtan uca gerçek bir gönderim doğrulaması istiyorsanız normal bir Mailable
gönderin (`Mail::to(...)->send(...)`) ve kutuyu kontrol edin.

Gönderici adresi şu sırayla belirlenir: `--from` → `SWMAILERPRO_FROM_EMAIL`
(`swmailerpro.defaults.from_email`) → `mail.from.address`. Alan adı gateway'de
tenant'ınıza kayıtlı değilse istek **403** ile döner (`TENANT_NOT_FOUND` veya
`SENDER_NOT_AUTHORIZED`).

`siz@yourdomain.com` ve `yourdomain.com` yer tutucudur — kendi adresinizi ve
alan adınızı yazın. Dokümandaki diğer örneklerde geçen `example.com` bilerek
seçilmiştir: RFC 2606 gereği rezervedir ve mail kabul etmez; gateway de gerçek
gönderim yolunda rezerve alan adlı alıcıları 422 `RESERVED_DOMAIN_RECIPIENTS` ile
reddeder. Örnekleri olduğu gibi kopyalayıp canlıya göndermeyin.

Opsiyon olarak `--from` ve `--subject` de verilebilir:

```bash
php artisan swmailerpro:test \
    --to=siz@yourdomain.com \
    --from=noreply@yourdomain.com \
    --subject="Deploy Doğrulama"
```

---

## 7. Kullanım Modları

### Mod 1: Laravel Mail Transport (Mevcut Mailable'lar)

Mevcut Mailable'larınız değişmeden çalışır:

```php
use App\Mail\WelcomeMail;
use Illuminate\Support\Facades\Mail;

// Senkron
Mail::to($user->email)->send(new WelcomeMail($user));

// Queue ile
Mail::to($user->email)->queue(new WelcomeMail($user));
```

**Template kullanımı** (custom header'lar ile):

```php
public function content(): Content
{
    // Mailable'ın boş olmayan bir gövdeye ihtiyacı var; gateway template'i
    // render edip content'i baştan yazdığı için bu boşluk teslim edilmez.
    // '' (boş string) KULLANMAYIN: falsy olduğu için html hiç set edilmez ve
    // Laravel gönderimi "InvalidArgumentException: Invalid view" ile durdurur.
    return new Content(htmlString: ' ');
}

public function build(): static
{
    return $this->withSymfonyMessage(function ($message) {
        $message->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'tpl_welcome');
        $message->getHeaders()->addTextHeader('X-SwMailerPro-Data', json_encode([
            'user_name' => $this->user->name,
        ], JSON_THROW_ON_ERROR));
    });
}
```

> **Konu.** Payload'da bir `subject` varsa gateway template'in konusunu kullanmaz.
> Mailable yolunda payload her zaman konuludur — `Envelope`'a konu vermezseniz
> Laravel sınıf adından bir konu türetir — yani template'in konusu Mailable
> üzerinden devreye giremez. Konuyu `Envelope`'ta yazın.

### Mod 2: Facade / Direct API

```php
use SabahWeb\SwMailerPro\Facades\SwMailerPro;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;

$factory = new PayloadFactory();
$payload = $factory->fromArray([
    'from' => ['email' => 'noreply@yourdomain.com', 'name' => 'My App'],
    'personalizations' => [
        ['to' => [['email' => 'user@example.com']]],
    ],
    'subject' => 'Direct API Test',
    'content' => [
        ['type' => 'text/html', 'value' => '<h1>Merhaba</h1>'],
    ],
]);

$response = SwMailerPro::send($payload);
```

---

## 8. Event Listener'lar (Opsiyonel)

Transport modu her gönderimde event dispatch eder. Bunları dinleyebilirsiniz:

```php
// app/Providers/EventServiceProvider.php veya bootstrap/app.php
use SabahWeb\SwMailerPro\Events\EmailSent;
use SabahWeb\SwMailerPro\Events\EmailFailed;

// Laravel 12 / 13 — bootstrap/app.php
->withEvents(function () {
    Event::listen(EmailSent::class, function (EmailSent $event) {
        logger()->info($event->queued ? 'Email queued' : 'Email sent', [
            'request_id' => $event->requestId,
            'provider'   => $event->response['data']['provider'] ?? null,
            // async gönderimde true: gateway kabul etti, teslim etmedi.
            'queued'     => $event->queued,
        ]);
    });

    Event::listen(EmailFailed::class, function (EmailFailed $event) {
        logger()->error('Email failed', [
            'error' => $event->exception->getMessage(),
        ]);
    });
})
```

> **`provider` her modda gelmez.** Gateway'de kalıcı kuyruk açıkken
> (`QUEUE_ASYNC_SENDS=true`) `/send-async` yanıtı henüz bir sağlayıcı seçilmediği
> için `provider` ve `provider_message_id` taşımaz; onun yerine `state: "queued"`,
> `queue_id` ve `log_id` döner. Yukarıdaki `?? null` bu yüzden gerekli — o modda
> sağlayıcıyı ve teslimatı webhook bildirir. Üç yanıt şeklinin tamamı README'deki
> "API Yanıt Formatı" bölümünde.

---

## 9. Production Checklist

Canlıya almadan önce bu listeyi doğrulayın:

| # | Kontrol | Nasıl |
|---|---|---|
| 1 | `.env` dosyasında `SWMAILERPRO_URL` ve `SWMAILERPRO_KEY` set edildi | `php artisan swmailerpro:health` |
| 2 | `MAIL_MAILER=swmailerpro` ayarlandı | `config/mail.php` ve `.env` |
| 3 | `config/mail.php` → `mailers` dizisinde `swmailerpro` tanımı var | Dosyayı kontrol et |
| 4 | Gönderici alan adı tenant'a kayıtlı (`SWMAILERPRO_FROM_EMAIL`) | `.env` ve gateway tenant kaydı |
| 5 | Config cache temizlendi | `php artisan config:clear && php artisan config:cache` |
| 6 | Gateway'den 200 yanıtı geliyor | `php artisan swmailerpro:health` |
| 7 | Payload doğrulaması geçiyor (dry-run — mail gitmez) | `php artisan swmailerpro:test --to=siz@yourdomain.com` |
| 8 | **Gerçek** bir test maili kutuya ulaştı | Uygulamadan bir Mailable gönderin: `Mail::to('siz@yourdomain.com')->send(...)` |
| 9 | Queue worker çalışıyor (queue kullanıyorsanız) | `php artisan queue:work` |
| 10 | `.env` dosyası `.gitignore`'da | `cat .gitignore \| grep .env` |

---

## 10. Production Timeout ve Retry Ayarları

Varsayılan değerler çoğu senaryo için uygundur. İhtiyaca göre `.env` ile override edin:

```env
# Transport (Mailable gönderimler)
SWMAILERPRO_TRANSPORT_TIMEOUT=30

# Client (Facade / Artisan komutları)
SWMAILERPRO_CLIENT_TIMEOUT=30
```

Retry yapısı sadece geçici hatalarda devreye girer (429 rate limit, 5xx server error, bağlantı kopması). Kalıcı hatalar (400, 401, 403) anında başarısız olur.

Yanıt bir `Retry-After` taşıyorsa — 429 da olsa 5xx de olsa — istenen bekleme
`max_retry_after` tavanını aşarsa hiç beklenmez; istek anında hatayla döner ve işi
kuyruk tekrarlar. Varsayılan tavan 5 saniyedir. Başlığın hem saniye hem RFC 7231
tarih biçimi okunur.

Başlığın yokluğu iki statüde farklı okunur: `Retry-After` taşımayan bir 429 hiç
denenmez, aynı durumdaki bir 5xx ise normal backoff ile denenir.

---

## 11. Sık Karşılaşılan Sorunlar

### "SwMailerPro: url ve key konfigürasyonu zorunludur"

`.env` dosyasında `SWMAILERPRO_URL` ve `SWMAILERPRO_KEY` tanımlı değil veya config cache eski:

```bash
php artisan config:clear
php artisan config:cache
```

### "Class 'swmailerpro' not found" veya transport tanınmıyor

Config dosyası publish edilmemiş ya da `config/mail.php` → `mailers` dizisinde `swmailerpro` tanımı eksik.

### Queue'daki mailler gönderilmiyor

Queue worker'ın çalıştığından emin olun:

```bash
php artisan queue:work --tries=3
```

### `Could not find package sabahweb/swmailerpro-laravel`

Paket o an Packagist'te yayımlanmamış. Yukarıdaki **1/B** adımındaki VCS yolunu
kullanın.

### `Your requirements could not be resolved` — symfony/mailer

Laravel 12 projelerinde tipik: kilitli `symfony/mailer` 7.2/7.3 sürümü bu paketin
istediği `^7.4`'ün altında.

```bash
composer update symfony/mailer symfony/mime --with-dependencies
```

### `The "1.0" constraint ... appears too strict` (Windows)

Komut satırına yazdığınız `^` karakteri Composer'ın `.bat` sarmalayıcısında
yutuldu; kısıt tam sürüm pinine döndü. Kısıtı hiç yazmayın
(`composer require sabahweb/swmailerpro-laravel`) ya da caret içermeyen bir biçim
kullanın (`"sabahweb/swmailerpro-laravel:1.0.*"`).

### `403 TENANT_NOT_FOUND` veya `403 SENDER_NOT_AUTHORIZED`

Gateway tenant'ı **`from` adresinin alan adından** çözüyor. Alan adı hiçbir
tenant'a kayıtlı değilse `TENANT_NOT_FOUND`, API anahtarınız başka bir tenant'a
aitse `SENDER_NOT_AUTHORIZED` gelir. En sık sebep: `SWMAILERPRO_FROM_EMAIL` boş
ve `mail.from.address` taze kurulumdaki `hello@example.com` değerinde kalmış.

```env
SWMAILERPRO_FROM_EMAIL=noreply@yourdomain.com
```

`config/mail.php` → `mail.from.address` değerini de aynı adrese çekmeniz iyi olur;
Mailable gönderimleri o değeri kullanır.

### `422 RESERVED_DOMAIN_RECIPIENTS`

Alıcıların tamamı RFC 2606/6761 rezerve alan adında (`example.com`, `*.test`,
`*.invalid`, `*.localhost`). Bu adresler tanım gereği mail alamaz; gerçek bir
alıcı adresi yazın.

### Health komutu timeout alıyor

Gateway URL'ini ve ağ erişimini kontrol edin. Timeout değerini artırabilirsiniz:

```env
SWMAILERPRO_CLIENT_TIMEOUT=60
```

---

## 12. Paket Güncelleme

```bash
composer update sabahweb/swmailerpro-laravel
```

Güncelleme sonrası:

```bash
php artisan config:clear
php artisan swmailerpro:health
# Dry-run: payload'ı doğrular, mail göndermez.
php artisan swmailerpro:test --to=siz@yourdomain.com
```

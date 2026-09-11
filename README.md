# SwMailerPro Laravel

SwMailerPro email gateway için resmi Laravel paketi. Mail transport ve doğrudan API client olarak iki kullanım modunu destekler.

[![Latest Tag](https://img.shields.io/github/v/tag/taskinsabah/swmailerpro-laravel.svg)](https://github.com/taskinsabah/swmailerpro-laravel/tags)
[![License](https://img.shields.io/github/license/taskinsabah/swmailerpro-laravel.svg)](LICENSE)

<!-- Packagist rozetleri: paket yayımlandığında yukarıdaki iki satırın yerine bunlar konabilir.
     Yayımlanmamış bir paket için shields.io "packagist: not found" yazan bir rozet üretir ve
     bağlantı 404'e gider — yani rozet, kurulumun çalıştığına dair yanlış bir söz verir.
[![Latest Version](https://img.shields.io/packagist/v/sabahweb/swmailerpro-laravel.svg)](https://packagist.org/packages/sabahweb/swmailerpro-laravel)
[![License](https://img.shields.io/packagist/l/sabahweb/swmailerpro-laravel.svg)](LICENSE)
-->

---

## Gereksinimler

- PHP 8.2+ (Laravel 13 kullanıyorsanız 8.3+)
- Laravel 12 veya 13
- **`symfony/mailer` ve `symfony/mime` 7.4+ (ya da 8.x)**
- SwMailerPro Gateway erişimi (URL + API Key)

> **Laravel 12 kullanıyorsanız dikkat.** Laravel 12'nin kendi tabanı `symfony/mailer: ^7.2.0`;
> bu paket ise `^7.4 || ^8.0` istiyor. Yani `composer.lock` dosyanız 7.2/7.3'te duruyorsa
> kurulum "your requirements could not be resolved" diye başarısız olur ve suçlu olarak bu
> paketi gösterir. Çözüm paketi değil Symfony'yi yükseltmek:
>
> ```bash
> composer update symfony/mailer symfony/mime --with-dependencies
> ```
>
> Laravel 13 zaten `^7.4 || ^8.0` istediği için orada böyle bir durum yok.

## Kurulum

### Packagist üzerinden (normal yol)

```bash
composer require sabahweb/swmailerpro-laravel
```

Sürüm kısıtı yazmayın: Composer yayımdaki en güncel sürüme bakıp `composer.json`'a
`"^1.0"` gibi doğru bir kısıt yazar. Bunu elle yazmak Windows'ta ayrıca risklidir —
bir alttaki uyarıya bakın.

### VCS üzerinden (Packagist'te bulunamıyorsa)

`Could not find package sabahweb/swmailerpro-laravel` hatası alıyorsanız paket o an
Packagist'te yayımlanmamış demektir. Aynı durum bir fork'tan ya da kendi özel aynanızdan
kurulum yaparken de geçerli. Depoyu Composer'a doğrudan tanıtın:

```bash
composer config repositories.swmailerpro vcs https://github.com/taskinsabah/swmailerpro-laravel
composer require sabahweb/swmailerpro-laravel
```

Bu yol `composer.json`'a bir `repositories` girdisi ekler; paket sonradan Packagist'e
yayımlandığında o girdiyi silip normal yola dönebilirsiniz.

> **Windows'ta `^` kısıtını komut satırına yazmayın.** Composer Windows'ta bir `.bat`
> sarmalayıcı üzerinden çalışır (`composer.bat` → `php composer.phar %*`) ve `cmd.exe`
> bu genişletme sırasında `^` karakterini kendi kaçış karakteri sanıp yutar. Tırnak
> işareti bunu **kurtarmaz**: PowerShell'de `pkg:^1.0`, `"pkg:^1.0"` ve `'pkg:^1.0'`
> üçü de Composer'a `pkg:1.0` olarak ulaşır — yani istediğiniz "1.x boyunca güncelle"
> kısıtı sessizce "tam olarak 1.0.0" pinine dönüşür. Belirtisi şu uyarıdır:
>
> ```
> The "1.0" constraint for "sabahweb/swmailerpro-laravel" appears too strict
> ```
>
> Güvenli olan iki biçim: kısıtı hiç yazmamak (yukarıdaki komutlar) ya da caret
> içermeyen bir kısıt kullanmak (`composer require "sabahweb/swmailerpro-laravel:1.0.*"`).
> `*` karakteri `.bat` sarmalayıcısından olduğu gibi geçer.

### Kurulum sonrası

Paket, Laravel auto-discover ile otomatik yüklenir. Config dosyasını publish edin:

```bash
php artisan vendor:publish --tag=swmailerpro-config
```

`.env` dosyasına gateway bilgilerini ekleyin:

```env
SWMAILERPRO_URL=https://mail.yourdomain.com
SWMAILERPRO_KEY=your-tenant-api-key

# Gönderici adresi: alan adı gateway'de sizin tenant'ınıza kayıtlı OLMALI.
# Boş bırakırsanız paket config/mail.php'deki mail.from.address değerine düşer —
# taze bir Laravel kurulumunda o değer hello@example.com'dur ve gateway reddeder.
SWMAILERPRO_FROM_EMAIL=noreply@yourdomain.com
```

Adım adım kurulum, production checklist'i ve sık karşılaşılan hatalar için
[doc.md](doc.md) — Entegrasyon ve Deploy Rehberi'ne bakın.

## Konfigürasyon

`config/swmailerpro.php` altı bölümden oluşur:

| Bölüm | Açıklama |
|---|---|
| `url` / `key` | Gateway bağlantısı — her iki mod paylaşır |
| `transport` | Mailable gönderimlerinde timeout/retry |
| `client` | Facade/direct API kullanımında timeout/retry |
| `idempotency` | Gönderim isteklerine `Idempotency-Key` eklenir (varsayılan açık) |
| `limits` | Gateway tavanlarının kopyası — aşan payload hiç gönderilmez |
| `defaults` | `from_email`, `async`, `tracking.open`, `tracking.click` |

```php
// config/swmailerpro.php
return [
    'url' => env('SWMAILERPRO_URL', 'http://localhost:3000'),
    'key' => env('SWMAILERPRO_KEY', ''),

    'transport' => [
        'timeout' => env('SWMAILERPRO_TRANSPORT_TIMEOUT', 30),
        'connect_timeout' => env('SWMAILERPRO_TRANSPORT_CONNECT_TIMEOUT', 10),
        'retry' => ['times' => 2, 'sleep' => 200],
        'max_retry_after' => 5,
    ],

    'client' => [
        'timeout' => env('SWMAILERPRO_CLIENT_TIMEOUT', 30),
        'connect_timeout' => env('SWMAILERPRO_CLIENT_CONNECT_TIMEOUT', 10),
        'retry' => ['times' => 2, 'sleep' => 200],
        // Gateway daha uzun bir bekleme isterse istek beklenmeden hatayla
        // döner. 0 = hiçbir Retry-After beklenmez (tavanı kapatmaz).
        'max_retry_after' => 5,
    ],

    // Tekrar denemeyi güvenli kılan şey: gateway aynı anahtarla gelen ikinci
    // isteği ilk yanıtı döndürerek karşılar, maili tekrar göndermez.
    'idempotency' => env('SWMAILERPRO_IDEMPOTENCY', true),

    // Gateway'in kendi sınırları. Aşan bir payload ağa hiç çıkmaz —
    // gateway zaten reddedecek, ama ancak 20 MB yüklendikten sonra.
    // Bir tavanı 0 yapmak o kontrolü kapatır.
    'limits' => [
        'attachments' => 10,
        'attachment_bytes' => 10 * 1024 * 1024,
        'attachments_total_bytes' => 15 * 1024 * 1024,
        'personalizations' => 1000,
        'body_bytes' => 20 * 1024 * 1024,
        // Yalnızca async yolda geçerli, çünkü kuyruğa alınan payload'ın tavanı
        // gateway'de ayrı ve çok daha düşük (QUEUE_MAX_PAYLOAD_BYTES, 2 MiB).
        'async_payload_bytes' => 2 * 1024 * 1024,
    ],

    'defaults' => [
        // Boşsa mail.from.address kullanılır. Taze bir Laravel kurulumunda o
        // adres hello@example.com'dur ve gateway hiçbir tenant'a bağlayamaz.
        'from_email' => env('SWMAILERPRO_FROM_EMAIL', ''),
        'async' => false,
        'tracking' => ['open' => null, 'click' => null],
    ],
];
```

---

## Kullanım Modları

SwMailerPro Laravel iki bağımsız modla çalışır. Aynı projede ikisini birden kullanabilirsiniz.

### Mod 1: Laravel Mail Transport (Mailable / Mail Facade)

Laravel'in standart mail sistemini kullanır. Mevcut Mailable'larınız değişmeden çalışır.

**1. Mail driver'ı ayarlayın:**

```env
MAIL_MAILER=swmailerpro
```

```php
// config/mail.php → mailers
'swmailerpro' => [
    'transport' => 'swmailerpro',
],
```

**2. Mailable oluşturun ve gönderin:**

```php
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeMail extends Mailable
{
    public function __construct(
        private readonly User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Hoş Geldiniz!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            with: ['user' => $this->user],
        );
    }
}

// Gönderim
Mail::to($user->email)->send(new WelcomeMail($user));

// Queue ile
Mail::to($user->email)->queue(new WelcomeMail($user));
```

**3. Template desteği (custom header'lar ile):**

```php
use Illuminate\Mail\Mailables\Envelope;

class OrderConfirmation extends Mailable
{
    public function envelope(): Envelope
    {
        // Bu konu template'inkini EZER — tersi değil. Ayrıntı aşağıda.
        return new Envelope(
            subject: 'Sipariş Onayı',
        );
    }

    public function content(): Content
    {
        // Tek boşluk bilerek: Mailable'ın bir gövdeye ihtiyacı var, gateway ise
        // template'i render edip content'i baştan yazıyor — yani bu boşluk
        // hiçbir zaman teslim edilmiyor. Boş string ('') KULLANMAYIN: falsy
        // olduğu için Mailable html'i hiç set etmez ve Laravel gönderimi
        // "InvalidArgumentException: Invalid view" ile daha paket koduna
        // girmeden durdurur.
        return new Content(htmlString: ' ');
    }

    public function build(): static
    {
        return $this->withSymfonyMessage(function ($message) {
            $message->getHeaders()->addTextHeader('X-SwMailerPro-Template', 'tpl_order_confirm');
            $message->getHeaders()->addTextHeader('X-SwMailerPro-Data', json_encode([
                'order_id' => $this->order->id,
                'total' => $this->order->total,
            ], JSON_THROW_ON_ERROR));
            $message->getHeaders()->addTextHeader('X-SwMailerPro-Transactional', 'true');
        });
    }
}
```

`new Content(htmlString: ' ')` yerine `build()` içinde `$this->html(' ')` da
kullanılabilir; ikisi de aynı payload'ı üretir (Laravel 13.30 üzerinde denendi).
Tercih ederseniz tek satırlık gerçek bir Blade view de olur — önemli olan gövdenin
boş olmaması.

**Konu (subject) kimin dediği olur?** Payload'da bir `subject` varsa gateway
template'in konusunu **kullanmaz**; template'in konusu yalnızca payload konusuz
geldiğinde devreye girer. Mailable yolunda payload pratikte her zaman konuludur:
`Envelope`'a konu vermezseniz Laravel sınıf adından bir konu türetir
(`OrderConfirmation` → "Order Confirmation") ve o gateway'e gider. Yani
**template'in konusunu Mailable üzerinden kullanamazsınız**; konuyu `Envelope`'ta
yazın. Template'in konusu ancak raw payload yolunda (Mod 2) `subject` alanını hiç
göndermediğinizde kullanılır.

Gövde tersi yönde çalışır: `template_id` varsa gateway render ettiği HTML/metni
payload'daki `content` alanının **üzerine yazar**. Yukarıdaki tek boşluk bu yüzden
zararsızdır.

**Desteklenen header'lar:**

| Header | Payload Karşılığı |
|---|---|
| `X-SwMailerPro-Template` | `template_id` |
| `X-SwMailerPro-Data` | `template_data` (JSON) |
| `X-SwMailerPro-Campaign` | `campaign_id` |
| `X-SwMailerPro-Transactional` | `transactional` — yalnızca `true` |

`X-SwMailerPro-Transactional` başlığı `true` dışında bir değer taşıyorsa gönderim
**yerelde reddedilir** ve `UnsupportedFeatureException` fırlatılır; gateway'e hiçbir
istek çıkmaz. Non-transactional mail gateway'de DKIM imzası şartına bağlı, DKIM kimliği
ise mesajın değil gönderim altyapısının ayarı — bu paket hangi alan adı adına imza
atıldığını iddia etmez. Aynı kural ham payload yolu için de geçerlidir:
`'transactional' => false` (ya da `0`, `'false'`, `null`) aynı şekilde reddedilir.

**Kendi başlıklarınız.** Mesaja eklediğiniz diğer başlıklar payload'a `headers`
olarak, **ham UTF-8** hâlleriyle girer — MIME kodlamasını sağlayıcı yapar, paket
değil. Yani `Ayşe Yılmaz` gateway'e o şekilde gider, `=?utf-8?Q?..?=` olarak değil.

Bir başlığın adında ya da değerinde satır sonu (CR veya LF) varsa gönderim
**yerelde reddedilir** ve `SwMailerProException` fırlatılır; gateway'e hiçbir istek
çıkmaz. Bir CRLF, sağlayıcının kurduğu mesaja yeni bir başlık yazma yoludur —
`"ok\r\nBcc: x@y"` tek bir başlık değil, bir başlık artı bir `Bcc`'dir. Ayıklamak
uygulamanın yazmadığı bir başlığı sessizce teslim etmek olurdu, o yüzden istek
durur.

### Mod 2: Facade / Direct API Client

SwMailerPro API'sine doğrudan raw payload ile çalışır. Laravel Mail akışından bağımsızdır.

```php
use SabahWeb\SwMailerPro\Facades\SwMailerPro;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;

// PayloadFactory ile doğrulama + gönderim
$factory = new PayloadFactory();
$payload = $factory->fromArray([
    // Alan adı gateway'de tenant'ınıza kayıtlı olmalı — example.com asla olmaz.
    'from' => ['email' => 'noreply@yourdomain.com', 'name' => 'My App'],
    'personalizations' => [
        [
            'to' => [['email' => 'user@example.com', 'name' => 'John']],
            'cc' => [['email' => 'manager@example.com']],
        ],
    ],
    'subject' => 'API ile Gönderim',
    'content' => [
        ['type' => 'text/html', 'value' => '<h1>Merhaba</h1>'],
    ],
    'tracking_settings' => [
        'open_tracking' => ['enable' => true],
        'click_tracking' => ['enable' => true],
    ],
]);

// Senkron gönderim
$response = SwMailerPro::send($payload);

// Asenkron gönderim (gateway kuyruğa alır)
$response = SwMailerPro::sendAsync($payload);

// Dry-run test — gerçek gönderim yok
$response = SwMailerPro::sendTest($payload);

// Health check
$health = SwMailerPro::health();
```

**Template ile direct API:**

```php
$payload = $factory->fromArray([
    'from' => ['email' => 'noreply@yourdomain.com'],
    'personalizations' => [
        ['to' => [['email' => 'user@example.com']]],
    ],
    // subject VERİLMEDİ — template'in kendi konusu ancak böyle kullanılır.
    'template_id' => 'tpl_password_reset',
    'template_data' => [
        'reset_url' => $resetUrl,
        'user_name' => $user->name,
    ],
]);

$response = SwMailerPro::send($payload);
```

---

## Queue Entegrasyonu

Mailable'lar Laravel Queue sistemiyle çalışır:

```php
// Otomatik queue
Mail::to($user)->queue(new WelcomeMail($user));

// Geciktirilmiş
Mail::to($user)->later(now()->addMinutes(10), new WelcomeMail($user));

// ShouldQueue interface
class WelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;
}
```

> **Not:** Facade kullanımında (`SwMailerPro::send()`) queue yönetimi sizin sorumluluğunuzdadır. Laravel Job içinde sarabilirsiniz.

---

## Artisan Komutları

### `swmailerpro:health`

Gateway sağlık durumunu kontrol eder:

```bash
php artisan swmailerpro:health
```

Çıktı: durum, uptime, sürüm, şema sürümü, provider tablosu (circuit breaker ve hata oranıyla),
kuyruk derinliği ve en eski bekleyen iş, ölü mektup sayısı, engelli adres sayısı.

Komut şu durumlarda **exit 1** döner — hepsi gateway "healthy" derken maili durdurur:

- **Şema geride**: migration çalışmamış, deploy yarım kalmış.
- **Şema ileride**: veritabanı bu paketin beklediğinden yeni, kod eski kalmış.
- **Şema okunamadı**: gateway veritabanına erişemiyor (`schema_version: null`).
- **Kuyruk ilerlemiyor**: bekleyen iş var ve en eskisi 5 dakikayı geçmiş (worker durmuş olabilir).
- **Kuyruk durumu okunamadı**: gateway kuyruğu okuyamadığını bildiriyor. Eskiden bu "bekleyen 0,
  ölü mektup 0" diye yazılıp exit 0 dönüyordu — bilmemek sağlık sayılmaz.

> Deploy hattınız bu komutu kapı olarak kullanıyorsa: son üç madde yeni. Daha önce sessizce
> geçen bir gateway artık hattı durdurabilir; amaç budur.

### `swmailerpro:test`

Payload'ı gateway'in `/api/v1/email/send-test` ucunda doğrular ve render eder.
**Dry-run'dır: hiçbir mail gönderilmez, hiçbir kutuya bir şey düşmez** — uçtan uca
gönderim testi için normal bir Mailable kullanın. Komut zaten "Payload doğrulandı.
Bu bir dry-run: mail GÖNDERİLMEDİ." yazar.

**Önce göndericiyi ayarlayın.** Gateway tenant'ı `from` adresinin alan adından
çözer; o alan adı bir tenant'a kayıtlı değilse istek daha gönderim aşamasına
gelmeden **403** ile döner (`TENANT_NOT_FOUND`, ya da anahtarınız başka bir
tenant'a aitse `SENDER_NOT_AUTHORIZED`). Taze bir Laravel kurulumunda
`config/mail.php` → `mail.from.address` değeri `hello@example.com`'dur; komuta
`--from` vermezseniz ve `SWMAILERPRO_FROM_EMAIL` boşsa komut tam olarak o adresi
kullanır ve istek reddedilir. Bu yüzden `.env`'e:

```env
SWMAILERPRO_FROM_EMAIL=noreply@yourdomain.com
```

Öncelik sırası: `--from` seçeneği → `swmailerpro.defaults.from_email`
(`SWMAILERPRO_FROM_EMAIL`) → `mail.from.address`.

```bash
# Gönderici .env'den (SWMAILERPRO_FROM_EMAIL) gelir
php artisan swmailerpro:test --to=siz@yourdomain.com

# Göndericiyi komutta vermek
php artisan swmailerpro:test --to=siz@yourdomain.com --from=noreply@yourdomain.com

# Tüm seçenekler
php artisan swmailerpro:test --to=siz@yourdomain.com --from=noreply@yourdomain.com --subject="Test Mail"
```

> `yourdomain.com` yer tutucudur — gateway'de tenant'ınıza kayıtlı kendi alan
> adınızla değiştirin; `siz@yourdomain.com` yerine de kendi adresinizi yazın.
> Bu sayfadaki diğer örneklerde geçen `example.com` bilerek seçilmiştir: RFC 2606
> gereği rezervedir ve mail kabul etmez, dolayısıyla yanlışlıkla bir yabancıya mail
> gitmez. Gateway de gerçek gönderim yolunda rezerve alan adlı alıcıları reddeder
> (422 `RESERVED_DOMAIN_RECIPIENTS`) — örnekleri olduğu gibi kopyalayıp canlıya
> göndermeyin. Not: bu alıcı süzgeci dry-run ucunda çalışmaz, yani
> `swmailerpro:test --to=...@example.com` alıcı yüzünden değil, yalnızca gönderici
> yüzünden hata verir.

---

## Events

Transport üzerinden gönderilen her mail için event dispatch edilir:

### `EmailSent`

```php
use SabahWeb\SwMailerPro\Events\EmailSent;

class HandleEmailSent
{
    public function handle(EmailSent $event): void
    {
        // $event->payload   — gönderilen payload
        // $event->response  — API yanıtı
        // $event->requestId — gateway request ID
        // $event->queued    — true ise gateway kuyruğa aldı, HENÜZ TESLİM ETMEDİ
        //                     (async gönderimde 202). Teslimat webhook ile bildirilir.
        // $event->suppressedRecipients — engelli listedeki alıcılar çıkarıldı,
        //                     mesaj kalanlara gitti. Alıcıların TAMAMI elenirse
        //                     gateway 422 döner ve bu event hiç yayınlanmaz;
        //                     o durumu EmailFailed / ApiException ile yakalayın.
        //
        // Not: payload'daki ek içerikleri event'e KONULMAZ ('content' => null,
        // yerine 'size_bytes'). Kuyruğa alınmış bir dinleyici event'i serialize
        // eder; 10 MB'lık bir ek jobs/failed_jobs tablosuna ~14 MB olarak yazılırdı.
        // Gateway'e giden mail elbette eki tam olarak taşır.
        
        Log::info('Email sent', [
            'to' => $event->payload['personalizations'][0]['to'][0]['email'] ?? null,
            'request_id' => $event->requestId,
            // DİKKAT: async + gateway'de kuyruk açıkken bu alan YOKTUR (null).
            // Sağlayıcıyı o modda ancak webhook bildirir. Aşağıya bakın.
            'provider' => $event->response['data']['provider'] ?? null,
        ]);
    }
}
```

**`$event->response` iki (aslında üç) farklı şekil taşır.** `queued` bayrağı
config'deki `defaults.async` değerini yansıtır — yani hangi ucun çağrıldığını,
gateway'in ne yaptığını değil:

| Mod | `data` içeriği |
|---|---|
| Senkron (`async` kapalı, `/send`) | `status` (sayı — sağlayıcının HTTP kodu, 200/202), `message`, `provider`, `provider_message_id`, elenen alıcı varsa `suppressed_recipients` |
| Async, gateway kuyruğu kapalı (`/send-async`, `QUEUE_ASYNC_SENDS=false`) | senkrondakiyle aynı alanlar, `message` = "Email queued for delivery" |
| Async, gateway kuyruğu açık (`/send-async`, `QUEUE_ASYNC_SENDS=true`) | `status` (202), `state` = `"queued"`, `message`, `queue_id`, `log_id` — **`provider` ve `provider_message_id` YOK** |

Üçüncü modda mesaj henüz hiçbir sağlayıcıya verilmemiştir; sağlayıcıyı worker
seçer. Bu yüzden `provider` okuyan bir dinleyici orada sessizce `null` görür ve
`Illuminate\Mail\SentMessage`'ın Message-ID'si de gateway'inkiyle
değiştirilmez (değiştirecek bir `provider_message_id` yoktur). Sağlayıcıyı ve
teslimatı o modda webhook bildirir.

### `EmailFailed`

```php
use SabahWeb\SwMailerPro\Events\EmailFailed;

class HandleEmailFailed
{
    public function handle(EmailFailed $event): void
    {
        // $event->payload   — gönderilemyen payload
        // $event->exception — hata detayı

        Log::error('Email failed', [
            'error' => $event->exception->getMessage(),
            'payload' => $event->payload,
        ]);
    }
}
```

> **Not:** Facade kullanımında (`SwMailerPro::send()`) event dispatch **yapılmaz**. Client framework-agnostic kalır. Event'leri kendi kodunuzda handle edebilirsiniz.

---

## Testing

Testlerde `Http::fake()` ile gateway'i taklit edin:

```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use SabahWeb\SwMailerPro\Events\EmailSent;

public function test_welcome_mail_sends_correctly(): void
{
    Event::fake([EmailSent::class]);

    Http::fake([
        // Gerçek gateway yanıtıyla aynı şekil: status bir metin değil,
        // sağlayıcının HTTP kodu.
        '*/api/v1/email/send' => Http::response([
            'success' => true,
            'data' => [
                'status' => 200,
                'message' => 'Email sent successfully',
                'provider' => 'mailchannels',
                'provider_message_id' => 'msg_abc123',
            ],
            'request_id' => 'req_test',
        ], 200),
    ]);

    Mail::to('user@example.com')->send(new WelcomeMail($user));

    Http::assertSent(function ($request) {
        return $request['from']['email'] === 'noreply@example.com'
            && $request['personalizations'][0]['to'][0]['email'] === 'user@example.com';
    });

    Event::assertDispatched(EmailSent::class);
}
```

Facade kullanımında:

```php
Http::fake([
    '*/api/v1/email/send' => Http::response([
        'success' => true,
        'data' => ['status' => 200, 'provider' => 'mailchannels'],
        'request_id' => 'req_test',
    ], 200),
]);

$result = SwMailerPro::send($payload);

$this->assertTrue($result['success']);
```

---

## Hata Yönetimi

```php
use SabahWeb\SwMailerPro\Exceptions\ApiException;
use SabahWeb\SwMailerPro\Exceptions\ConfigurationException;
use SabahWeb\SwMailerPro\Exceptions\ConnectionFailedException;
use SabahWeb\SwMailerPro\Exceptions\PayloadTooLargeException;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use SabahWeb\SwMailerPro\Exceptions\UnsupportedFeatureException;

try {
    $response = SwMailerPro::send($payload);
} catch (ApiException $e) {
    // Gateway API hatası
    $e->errorCode;   // 'VALIDATION_ERROR', 'QUOTA_EXCEEDED', vb. — tam liste aşağıda
    $e->httpStatus;  // 400, 429, 500, vb.
    $e->errorBody;   // ['error' => ['code' => '...', 'message' => '...']]
    $e->requestId;   // Gateway loglarında bu isteği bulmak için
    $e->getMessage(); // "SwMailerPro API Error [CODE]: message"
} catch (ConnectionFailedException $e) {
    // Gateway'e hiç ulaşılamadı: DNS, TLS, bağlantı ya da yanıt zaman aşımı.
    // Bu ve ApiException, Symfony'nin transport sözleşmesini uygular — yani
    // MAIL_MAILER=failover altında yedek taşıyıcı denenir. Yerel retler
    // (PayloadTooLarge, UnsupportedFeature) bilerek uygulamaz: onlar
    // "bu mesaj gönderilmemeli" der, yedeğe düşmek o kararı delerdi.
} catch (UnsupportedFeatureException $e) {
    // Paketin desteklemediği bir yetenek istendi (bugün: non-transactional
    // gönderim). İstek ağa hiç çıkmadı; tekrar denemek düzeltmez.
} catch (PayloadTooLargeException $e) {
    // Gateway tavanını aşıyor; yüklemeye başlamadan yerelde reddedildi.
    // config('swmailerpro.limits') altındaki her tavan için geçerli — async
    // yolda ayrıca async_payload_bytes (2 MiB) de burada kontrol edilir.
} catch (ConfigurationException $e) {
    // URL veya API Key eksik
} catch (SwMailerProException $e) {
    // Genel paket hatası (payload validasyon vb.)
}
```

### Gateway'in döndürdüğü hata kodları

`$e->errorCode` aşağıdaki değerlerden birini taşır. Liste gateway kodundan
çıkarıldı; eskiden burada yazan `RATE_LIMIT` gateway'de hiç üretilmeyen bir
koddur — o kodu bekleyen bir `match`/`switch` hiçbir zaman eşleşmez.

| Kod | HTTP | Ne oldu |
|---|---|---|
| `VALIDATION_ERROR` | 400 | Payload şemaya uymuyor; `details` alan alan söyler |
| `INVALID_JSON` | 400 | Gövde JSON olarak okunamadı |
| `INVALID_IDEMPOTENCY_KEY` | 400 | `Idempotency-Key` biçimi kabul edilmedi |
| `TEMPLATE_NOT_FOUND` | 404 | `template_id` bu tenant'ta yok |
| `TEMPLATE_MISSING_VARIABLES` | 400 | `template_data` zorunlu değişkenleri karşılamıyor |
| `TEMPLATE_RENDER_ERROR` | 400 | Template render edilirken patladı |
| `UNAUTHORIZED` | 401 | API anahtarı yok ya da geçersiz |
| `TENANT_NOT_FOUND` | 403 | `from` alan adı hiçbir tenant'a kayıtlı değil |
| `TENANT_SUSPENDED` | 403 | Tenant askıya alınmış |
| `SENDER_NOT_AUTHORIZED` | 403 | Gönderici (`from`, `envelope_from` ya da personalization `from`) bu tenant'ın alan adında değil |
| `API_KEY_TENANT_MISMATCH` | 403 | Anahtar başka bir tenant'a ait |
| `IP_NOT_ALLOWED` | 403 | Çağıran IP tenant'ın allowlist'inde değil |
| `PAYLOAD_TOO_LARGE` | 413 | Gövde 20 MB'ı, ya da async kuyrukta 2 MiB'ı aşıyor |
| `ATTACHMENT_TOO_LARGE` | 413 | Tek ek ya da eklerin toplamı tavanı aşıyor |
| `ALL_RECIPIENTS_SUPPRESSED` | 422 | Alıcıların tamamı suppression listesinde |
| `RESERVED_DOMAIN_RECIPIENTS` | 422 | Alıcıların tamamı RFC 2606/6761 rezerve alan adında (`example.com`, `*.test`, …) |
| `RATE_LIMITED` / `RATE_LIMIT_EXCEEDED` | 429 | İstek hızı sınırı; `Retry-After` taşır |
| `QUOTA_EXCEEDED` | 429 | Tenant kotası doldu; `Retry-After` taşır |
| `PROVIDER_TIMEOUT` / `PROVIDER_NETWORK_ERROR` / `PROVIDER_SERVER_ERROR` | 502 | Sağlayıcıya ulaşılamadı ya da sağlayıcı 5xx döndü (gateway sağlayıcının 5xx'ini 502'ye çevirir) |
| `PROVIDER_AUTH_ERROR` / `PROVIDER_CLIENT_ERROR` | 502 | Sağlayıcı isteği reddetti |
| `PROVIDER_NOT_CONFIGURED` | 502 | Hiçbir sağlayıcı yapılandırılmamış |
| `SERVICE_UNAVAILABLE` / `CIRCUIT_BREAKER_OPEN` | 503 | Circuit breaker açık; `Retry-After` taşır. Gateway kodu hatanın hangi katmandan geldiğine göre ikisinden birini döndürür — ikisini de karşılayın |
| `INTERNAL_ERROR` | 500 | Beklenmeyen gateway hatası |

Bu listenin dışında iki kodu paketin kendisi üretir: gateway JSON yerine başka bir
şey döndürdüğünde (araya giren bir proxy ya da oturum sayfası) `INVALID_RESPONSE`,
hata gövdesinde okunabilir bir `error.code` bulunmadığında ise `UNKNOWN`.

### Retry Stratejisi

Client sadece geçici hatalarda tekrar dener:
- **5xx** Server Error → retry — ama yanıt bir `Retry-After` taşıyorsa o da bağlayıcıdır
  (aşağıdaki tavan kuralı).
- **ConnectionException** → retry
- **429** Too Many Requests → yanıttaki `Retry-After` beklenir ve tekrar denenir.
  `Retry-After` **taşımayan** bir 429 hiç denenmez: ne kadar bekleneceğini bilmeden
  tekrar denemek, henüz sıfırlanmamış bir limite bir deneme daha harcamaktır.
- **4xx** (400, 401, 403) → **retry yapılmaz** (kalıcı hatalar)

**Retry-After tavanı.** 429 ve 5xx fark etmez: gateway tavandan daha uzun bir bekleme
isterse istek beklenmeden hatayla döner. Bir worker'ı tek bir mesaj için dakikalarca
bloke etmek, hatayı kuyruğa geri vermekten pahalıdır — kuyruk aynı işi çok daha ucuza
tekrarlar. Tavan varsayılan **5 saniye**, `client.max_retry_after` ve
`transport.max_retry_after` ile ayarlanır. Buradaki `0` "tavan yok" değil, "hiçbir
`Retry-After` beklenmez" demektir. Başlık taşımayan bir 5xx bu kuralın dışındadır;
normal backoff ile denenir.

Başlığın her iki RFC 7231 biçimi de okunur: saniye (`Retry-After: 30`) ve HTTP tarihi
(`Retry-After: Wed, 21 Oct 2015 07:28:00 GMT`). Okunamayan bir değer, başlık hiç
yokmuş gibi ele alınır.

Her gönderim isteği bir `Idempotency-Key` taşır (mesajın Message-ID'si). Aynı mesajın her
denemesi aynı anahtarı kullanır, dolayısıyla gateway'in kabul ettiği bir mail zaman aşımı
sonrası tekrar denendiğinde ikinci kez gönderilmez. Kuyruktaki bir job yeniden denendiğinde
mesaj baştan kurulur, yeni bir Message-ID alır ve olması gerektiği gibi yeni bir mail olarak gider.

---

## API Yanıt Formatı

### Başarılı Yanıt — senkron (`/send`, HTTP 200)

`data.status` bir metin değil **sayıdır**: sağlayıcının döndürdüğü HTTP kodu.
Gönderim başarılı sayıldığında bile 202 olabilir (sağlayıcı kabul etti, teslim
etmedi), bu durumda gateway de 202 döner.

```json
{
    "success": true,
    "data": {
        "status": 200,
        "message": "Email sent successfully",
        "provider": "mailchannels",
        "provider_message_id": "msg_abc123"
    },
    "request_id": "req_uuid"
}
```

Alıcıların bir kısmı elenmişse `data.suppressed_recipients` (dizi) eklenir ve
`message` "Email sent (some recipients were suppressed)" olur. Alıcıların tamamı
elenirse başarılı yanıt hiç gelmez — 422 döner.

### Başarılı Yanıt — asenkron (`/send-async`, HTTP 202)

Gateway'de kalıcı kuyruk **kapalıysa** (`QUEUE_ASYNC_SENDS=false`, varsayılan)
şekil senkrondakiyle aynıdır, yalnızca `message` "Email queued for delivery" olur.

Kuyruk **açıksa** mesaj sağlayıcıya henüz verilmemiştir; yanıt kuyruk kaydını
tarif eder ve `provider` / `provider_message_id` **bulunmaz**:

```json
{
    "success": true,
    "data": {
        "status": 202,
        "state": "queued",
        "message": "Email accepted and queued for delivery",
        "queue_id": 1234,
        "log_id": 5678
    },
    "request_id": "req_uuid"
}
```

### Başarılı Yanıt — dry-run (`/send-test`, HTTP 200)

Gönderim yok; `status` ve mesaj kimliği de yok. `swmailerpro:test` komutunun
tablosu tam olarak bu alanları basar.

```json
{
    "success": true,
    "data": {
        "message": "Dry-run successful — email not sent",
        "provider": "mailchannels",
        "rendered_messages": ["..."]
    },
    "request_id": "req_uuid"
}
```

### Hata Yanıtı

```json
{
    "success": false,
    "error": {
        "code": "VALIDATION_ERROR",
        "message": "from is required",
        "details": { "from": ["email field is required"] }
    },
    "request_id": "req_uuid"
}
```

---

## Mimari

```
┌─────────────────────────────────────────────────────┐
│                 Laravel Uygulaması                    │
├──────────────────────┬──────────────────────────────┤
│  Mod 1: Mail/Mailable│  Mod 2: Facade/Direct API    │
│  Mail::to()->send()  │  SwMailerPro::send($payload)  │
│         │            │           │                    │
│         ▼            │           ▼                    │
│  SwMailerProTransport│  SwMailerProClient             │
│    ┌────┴────┐       │  (saf HTTP — event yok)       │
│    │         │       │                                │
│    ▼         ▼       │                                │
│ Payload   Client     │                                │
│ Factory              │                                │
├──────────────────────┴──────────────────────────────┤
│              PayloadFactory (tek kaynak)              │
│      fromEmail(Email) ←→ fromArray(array)            │
├─────────────────────────────────────────────────────┤
│              SwMailerPro Gateway API                  │
└─────────────────────────────────────────────────────┘
```

- **PayloadFactory**: Tek payload üretim noktası — drift riski sıfır
- **Transport**: Saf adaptör — payload üretmez, HTTP yapmaz
- **Client**: Framework-agnostic — event dispatch yapmaz
- **Events**: Sadece Transport ve Commands'da dispatch edilir

---

## V2 Yol Haritası

- [ ] Notification Channel
- [ ] TemplateMailable sınıfı
- [ ] WebhookController (event handling)
- [ ] Bulk send

### Paket kapsamı dışında: operatör uçları

Suppression list yönetimi, template CRUD, gönderim logları ve domain/DKIM yönetimi
bu pakete eklenmeyecek. Gateway'de bu uçlar tenant anahtarıyla değil `INTERNAL_API_KEY`
ile korunuyor ve tenant kimliğini isteğin kendisinden okuyorlar — yani bir Laravel
uygulamasına o anahtarı vermek, ona yalnızca kendi kayıtlarını değil bütün tenant'ların
kayıtlarını açmak demek. Bunlar operatör uçlarıdır.

Uygulamanın gerçekte ihtiyaç duyduğu şey — "bu adrese gönderilebilir mi" — gönderim
yolunda zaten karşılanıyor: gateway alıcıları tenant bazlı süzüyor, kısmi elemede
kalanları `EmailSent::$suppressedRecipients` ile bildiriyor, tamamı elenirse 422 ile
reddediyor. Tenant-kapsamlı bir okuma ucu istenirse o iş gateway tarafında, her
tenant'ın yalnız kendi kayıtlarını görebileceği bir yetkilendirmeyle yapılmalı.

---

## Lisans

MIT. Detaylar için [LICENSE](LICENSE) dosyasına bakın.

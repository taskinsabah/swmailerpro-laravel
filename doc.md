# SwMailerPro Laravel — Entegrasyon ve Deploy Rehberi

Bu rehber, `sabahweb/swmailerpro-laravel` paketini mevcut bir Laravel 12 projesine adım adım kurmayı ve production ortamında aktif etmeyi kapsar.

---

## Ön Koşullar

| Gereksinim | Minimum |
|---|---|
| PHP | 8.2+ |
| Laravel | 12.x |
| SwMailerPro Gateway | Erişilebilir URL + Tenant API Key |
| Composer | 2.x |

---

## 1. Paketi Yükle

### A) Packagist üzerinden (paket yayınlandıysa)

```bash
composer require sabahweb/swmailerpro-laravel
```

### B) GitHub reposu üzerinden (Packagist'e yayınlanmadan)

`composer.json` dosyanıza repository tanımı ekleyin:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/sabahweb/swmailerpro-laravel"
        }
    ]
}
```

Sonra kurulumu yapın:

```bash
composer require sabahweb/swmailerpro-laravel:dev-main
```

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
        'timeout' => env('SWMAILERPRO_TRANSPORT_TIMEOUT', 30),
        'retry'   => ['times' => 2, 'sleep' => 200],
    ],

    'client' => [
        'timeout' => env('SWMAILERPRO_CLIENT_TIMEOUT', 30),
        'retry'   => ['times' => 2, 'sleep' => 200],
    ],

    'defaults' => [
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

## 6. Test E-postası Gönder

```bash
php artisan swmailerpro:test --to=test@example.com
```

Opsiyon olarak `--from` ve `--subject` de verilebilir:

```bash
php artisan swmailerpro:test \
    --to=test@example.com \
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

// Laravel 12 — bootstrap/app.php
->withEvents(function () {
    Event::listen(EmailSent::class, function (EmailSent $event) {
        logger()->info('Email sent', [
            'request_id' => $event->requestId,
            'provider'   => $event->response['data']['provider'] ?? null,
        ]);
    });

    Event::listen(EmailFailed::class, function (EmailFailed $event) {
        logger()->error('Email failed', [
            'error' => $event->exception->getMessage(),
        ]);
    });
})
```

---

## 9. Production Checklist

Canlıya almadan önce bu listeyi doğrulayın:

| # | Kontrol | Nasıl |
|---|---|---|
| 1 | `.env` dosyasında `SWMAILERPRO_URL` ve `SWMAILERPRO_KEY` set edildi | `php artisan swmailerpro:health` |
| 2 | `MAIL_MAILER=swmailerpro` ayarlandı | `config/mail.php` ve `.env` |
| 3 | `config/mail.php` → `mailers` dizisinde `swmailerpro` tanımı var | Dosyayı kontrol et |
| 4 | Config cache temizlendi | `php artisan config:clear && php artisan config:cache` |
| 5 | Gateway'den 200 yanıtı geliyor | `php artisan swmailerpro:health` |
| 6 | Test e-postası ulaştı | `php artisan swmailerpro:test --to=real@address.com` |
| 7 | Queue worker çalışıyor (queue kullanıyorsanız) | `php artisan queue:work` |
| 8 | `.env` dosyası `.gitignore`'da | `cat .gitignore \| grep .env` |

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
php artisan swmailerpro:test --to=test@example.com
```

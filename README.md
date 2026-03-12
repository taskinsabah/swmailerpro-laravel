# SwMailerPro Laravel

SwMailerPro email gateway için resmi Laravel paketi. Mail transport ve doğrudan API client olarak iki kullanım modunu destekler.

[![Latest Version](https://img.shields.io/packagist/v/sabahweb/swmailerpro-laravel.svg)](https://packagist.org/packages/sabahweb/swmailerpro-laravel)
[![License](https://img.shields.io/packagist/l/sabahweb/swmailerpro-laravel.svg)](LICENSE)

---

## Gereksinimler

- PHP 8.2+
- Laravel 12+
- SwMailerPro Gateway erişimi (URL + API Key)

## Kurulum

```bash
composer require sabahweb/swmailerpro-laravel
```

Paket, Laravel auto-discover ile otomatik yüklenir. Config dosyasını publish edin:

```bash
php artisan vendor:publish --tag=swmailerpro-config
```

`.env` dosyasına gateway bilgilerini ekleyin:

```env
SWMAILERPRO_URL=https://mail.yourdomain.com
SWMAILERPRO_KEY=your-tenant-api-key
```

## Konfigürasyon

`config/swmailerpro.php` üç bölümden oluşur:

| Bölüm | Açıklama |
|---|---|
| `url` / `key` | Gateway bağlantısı — her iki mod paylaşır |
| `transport` | Mailable gönderimlerinde timeout/retry |
| `client` | Facade/direct API kullanımında timeout/retry |
| `defaults` | `async`, `tracking.open`, `tracking.click` |

```php
// config/swmailerpro.php
return [
    'url' => env('SWMAILERPRO_URL', 'http://localhost:3000'),
    'key' => env('SWMAILERPRO_KEY', ''),

    'transport' => [
        'timeout' => env('SWMAILERPRO_TRANSPORT_TIMEOUT', 30),
        'retry' => ['times' => 2, 'sleep' => 200],
    ],

    'client' => [
        'timeout' => env('SWMAILERPRO_CLIENT_TIMEOUT', 30),
        'retry' => ['times' => 2, 'sleep' => 200],
    ],

    'defaults' => [
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
        return new Envelope(
            subject: 'Sipariş Onayı', // template override edebilir
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: ''); // boş view workaround
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

**Desteklenen header'lar:**

| Header | Payload Karşılığı |
|---|---|
| `X-SwMailerPro-Template` | `template_id` |
| `X-SwMailerPro-Data` | `template_data` (JSON) |
| `X-SwMailerPro-Campaign` | `campaign_id` |
| `X-SwMailerPro-Transactional` | `transactional` (bool) |

### Mod 2: Facade / Direct API Client

SwMailerPro API'sine doğrudan raw payload ile çalışır. Laravel Mail akışından bağımsızdır.

```php
use SabahWeb\SwMailerPro\Facades\SwMailerPro;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;

// PayloadFactory ile doğrulama + gönderim
$factory = new PayloadFactory();
$payload = $factory->fromArray([
    'from' => ['email' => 'noreply@example.com', 'name' => 'My App'],
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
    'from' => ['email' => 'noreply@example.com'],
    'personalizations' => [
        ['to' => [['email' => 'user@example.com']]],
    ],
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

Çıktı: provider durumları, circuit breaker, uptime ve database bilgisi.

### `swmailerpro:test`

Test e-postası gönderir (dry-run):

```bash
# Basit test
php artisan swmailerpro:test --to=test@example.com

# Tüm seçenekler
php artisan swmailerpro:test --to=test@example.com --from=noreply@example.com --subject="Test Mail"
```

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
        
        Log::info('Email sent', [
            'to' => $event->payload['personalizations'][0]['to'][0]['email'] ?? null,
            'request_id' => $event->requestId,
            'provider' => $event->response['data']['provider'] ?? null,
        ]);
    }
}
```

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
        '*/api/v1/email/send' => Http::response([
            'success' => true,
            'data' => ['status' => 'sent', 'provider' => 'mailchannels'],
            'request_id' => 'req_test',
        ], 200),
    ]);

    Mail::to('user@test.com')->send(new WelcomeMail($user));

    Http::assertSent(function ($request) {
        return $request['from']['email'] === 'noreply@example.com'
            && $request['personalizations'][0]['to'][0]['email'] === 'user@test.com';
    });

    Event::assertDispatched(EmailSent::class);
}
```

Facade kullanımında:

```php
Http::fake([
    '*/api/v1/email/send' => Http::response([
        'success' => true,
        'data' => ['status' => 'sent'],
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
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;

try {
    $response = SwMailerPro::send($payload);
} catch (ApiException $e) {
    // Gateway API hatası
    $e->errorCode;   // 'VALIDATION_ERROR', 'RATE_LIMIT', vb.
    $e->httpStatus;  // 400, 429, 500, vb.
    $e->errorBody;   // ['error' => ['code' => '...', 'message' => '...']]
    $e->getMessage(); // "SwMailerPro API Error [CODE]: message"
} catch (ConfigurationException $e) {
    // URL veya API Key eksik
} catch (SwMailerProException $e) {
    // Genel paket hatası (payload validasyon vb.)
}
```

### Retry Stratejisi

Client sadece geçici hatalarda tekrar dener:
- **429** Too Many Requests → retry
- **5xx** Server Error → retry
- **ConnectionException** → retry
- **4xx** (400, 401, 403) → **retry yapılmaz** (kalıcı hatalar)

---

## API Yanıt Formatı

### Başarılı Yanıt

```json
{
    "success": true,
    "data": {
        "status": "sent",
        "message": "Email sent successfully",
        "provider": "mailchannels",
        "provider_message_id": "msg_abc123"
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
- [ ] Suppression list yönetimi
- [ ] Template CRUD
- [ ] Bulk send
- [ ] Domain/DKIM yönetimi

---

## Lisans

MIT. Detaylar için [LICENSE](LICENSE) dosyasına bakın.

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SwMailerPro API Bağlantısı
    |--------------------------------------------------------------------------
    |
    | Gateway URL ve Tenant API Key. Her iki kullanım modu (mail transport
    | ve facade/direct API) bu değerleri paylaşır.
    |
    */

    'url' => env('SWMAILERPRO_URL', 'http://localhost:3000'),

    'key' => env('SWMAILERPRO_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Transport Ayarları
    |--------------------------------------------------------------------------
    |
    | Laravel mail transport (Mail::to()->send()) için timeout ve retry
    | konfigürasyonu. Bu değerler sadece Mailable gönderimlerinde kullanılır.
    |
    | max_retry_after: gateway "Retry-After" ile ne kadar beklememizi isterse
    | istesin, bu tavandan uzun bir bekleme kabul edilmez — istek beklenmeden
    | hatayla döner ve işi kuyruk çok daha ucuza tekrarlar. Dikkat: buradaki 0,
    | aşağıdaki "limits"teki 0'ın aksine kontrolü KAPATMAZ; "hiçbir Retry-After
    | beklenmez" demektir.
    |
    */

    'transport' => [
        'timeout' => env('SWMAILERPRO_TRANSPORT_TIMEOUT', 30),
        // Ayrı tutuluyor: ulaşılamayan bir gateway saniyeler içinde
        // raporlanmalı, tüm yanıt bütçesi dolduktan sonra değil.
        'connect_timeout' => env('SWMAILERPRO_TRANSPORT_CONNECT_TIMEOUT', 10),
        'retry' => [
            'times' => 2,
            'sleep' => 200,
        ],
        'max_retry_after' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Ayarları
    |--------------------------------------------------------------------------
    |
    | Facade / direct API kullanımı (SwMailerPro::send()) için timeout ve
    | retry konfigürasyonu. Artisan komutları da bu ayarları kullanır.
    |
    | max_retry_after: gateway "Retry-After" ile ne kadar beklememizi isterse
    | istesin, bu tavandan uzun bir bekleme kabul edilmez — istek beklenmeden
    | hatayla döner ve işi kuyruk çok daha ucuza tekrarlar. Dikkat: buradaki 0,
    | aşağıdaki "limits"teki 0'ın aksine kontrolü KAPATMAZ; "hiçbir Retry-After
    | beklenmez" demektir.
    |
    */

    'client' => [
        'timeout' => env('SWMAILERPRO_CLIENT_TIMEOUT', 30),
        'connect_timeout' => env('SWMAILERPRO_CLIENT_CONNECT_TIMEOUT', 10),
        'retry' => [
            'times' => 2,
            'sleep' => 200,
        ],
        'max_retry_after' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Her gönderim isteğine Idempotency-Key başlığı eklenir; gateway aynı
    | anahtarla gelen tekrarı ilk yanıtı döndürerek karşılar. Tekrar denemeyi
    | (timeout, 5xx) güvenli kılan şey budur — kapatırsanız bir zaman aşımı
    | sonrası tekrar deneme aynı maili iki kez gönderebilir.
    |
    | Yalnızca Idempotency-Key desteklemeyen eski bir gateway için kapatın.
    |
    */

    'idempotency' => env('SWMAILERPRO_IDEMPOTENCY', true),

    /*
    |--------------------------------------------------------------------------
    | Boyut Tavanları
    |--------------------------------------------------------------------------
    |
    | Gateway'in kendi sınırlarının kopyası. Payload bunları aşıyorsa istek hiç
    | gönderilmez: gateway zaten reddedecek, ama ancak gövdenin tamamı
    | yüklendikten sonra — yavaş bir bağlantıda 20 MB boşa gider.
    |
    | Gateway sınırları değişirse burayı güncelleyin. Bir tavanı 0 yapmak o
    | kontrolü kapatır (kontrolü gateway yapmaya devam eder).
    |
    */

    'limits' => [
        'attachments' => 10,
        'attachment_bytes' => 10 * 1024 * 1024,
        'attachments_total_bytes' => 15 * 1024 * 1024,
        'personalizations' => 1000,
        'body_bytes' => 20 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Varsayılan Değerler
    |--------------------------------------------------------------------------
    |
    | Gönderim payloadlarına uygulanacak global default'lar. Her iki kullanım
    | modunda (transport ve facade) etkilidir. Per-call override edilebilir.
    |
    | async: true ise transport /send-async endpoint'ini kullanır.
    | tracking.open/click: null = gateway default'u kullanılır.
    |
    */

    'defaults' => [
        'async' => false,
        'tracking' => [
            'open' => null,
            'click' => null,
        ],
    ],

];

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
    */

    'transport' => [
        'timeout' => env('SWMAILERPRO_TRANSPORT_TIMEOUT', 30),
        'retry' => [
            'times' => 2,
            'sleep' => 200,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Ayarları
    |--------------------------------------------------------------------------
    |
    | Facade / direct API kullanımı (SwMailerPro::send()) için timeout ve
    | retry konfigürasyonu. Artisan komutları da bu ayarları kullanır.
    |
    */

    'client' => [
        'timeout' => env('SWMAILERPRO_CLIENT_TIMEOUT', 30),
        'retry' => [
            'times' => 2,
            'sleep' => 200,
        ],
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

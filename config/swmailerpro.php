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
    | body_bytes: gövdenin GÖNDERİLDİĞİ hâlinin bayt sayısı — yani JSON'a
    | kodlanmış hâli. Ekler orada base64 (≈4/3) ve JSON kaçışlarıyla duruyor;
    | gateway de gövdeyi bu hâliyle tartıyor. Çözülmüş ek baytlarını toplamak
    | ölçümü ~%33 hafif gösteriyordu, yani tavan tam işe yarayacağı bantta
    | susuyordu.
    |
    | header_overhead_bytes: gateway kendi hesabına sabit bir başlık payı
    | ekliyor (HEADERS_OVERHEAD_BYTES = 2048). Aynı payı biz de ekliyoruz ki
    | tam sınırdaki bir mesaj burada geçip orada reddedilmesin.
    |
    | async_payload_bytes: kuyruğa alma tavanı — YALNIZCA /send-async için.
    | Gateway kuyruk açıkken (QUEUE_ASYNC_SENDS) bunu aşan gövdeyi 413
    | PAYLOAD_TOO_LARGE ile reddediyor; aynı mesaj senkron /send ile
    | gönderilebilir. Gateway'de QUEUE_MAX_PAYLOAD_BYTES (varsayılan 2 MiB,
    | operatör 64.000 ile 20.000.000 bayt arasında değiştirebiliyor), bu yüzden
    | burası da env ile ezilebilir. Gateway'iniz QUEUE_ASYNC_SENDS=false ile
    | çalışıyorsa kuyruk hiç devrede değildir ve böyle bir tavan da yoktur:
    | 0 yapın, yoksa gateway'in kabul edeceği bir mesajı burada reddedersiniz.
    | Aynı sebeple, v1.0.0'dan kalma ve bu anahtarı taşımayan bir published
    | config için paketin kendi varsayılanı 0'dır — yayınlanmış bir kuruluma
    | görünmez bir tavan getirmemek için.
    |
    | *_chars ile biten tavanlar gateway'in şema sınırları: aşıldığında yanıt
    | 400 VALIDATION_ERROR oluyor — ama ancak gövdenin tamamı yüklendikten
    | sonra ve hangi alanın suçlu olduğunu söylemeden.
    |
    */

    'limits' => [
        'attachments' => 10,
        'attachment_bytes' => 10 * 1024 * 1024,
        'attachments_total_bytes' => 15 * 1024 * 1024,
        'personalizations' => 1000,
        'body_bytes' => 20 * 1024 * 1024,
        'header_overhead_bytes' => 2048,
        'async_payload_bytes' => (int) env('SWMAILERPRO_ASYNC_PAYLOAD_BYTES', 2 * 1024 * 1024),
        // subject (gövde ve personalization seviyesinde): RFC 5322'nin satır
        // sınırı.
        'subject_chars' => 998,
        // content[].value
        'content_value_chars' => 5000000,
        // from / reply_to / envelope_from / to / cc / bcc içindeki "name"
        'address_name_chars' => 256,
        // attachments[].filename
        'filename_chars' => 256,
    ],

    /*
    |--------------------------------------------------------------------------
    | Varsayılan Değerler
    |--------------------------------------------------------------------------
    |
    | Gönderim payloadlarına uygulanacak default'lar. Hangi anahtarın nereye
    | işlediği aşağıda tek tek yazıyor — blok bir bütün olarak "her yerde
    | geçerli" DEĞİL. Servis sağlayıcı bu bloğu yalnızca mail transport'una
    | geçiriyor; facade/client yolu (SwMailerPro::send()) payload'ı çağırandan
    | aldığı gibi gönderiyor, buradaki hiçbir değeri eklemiyor.
    |
    | async: YALNIZCA transport. true ise Mail::to()->send() /send-async
    |     endpoint'ini kullanır. Facade'de karşılığı SwMailerPro::sendAsync().
    | tracking.open/click: YALNIZCA transport. null = gateway default'u.
    | from_email: YALNIZCA "php artisan swmailerpro:test" komutu. Boş
    |     bırakılırsa komut mail.from.address'e düşer; taze bir Laravel
    |     kurulumunda orası hello@example.com'dur ve gateway gönderen alan
    |     adından tenant çözdüğü için bu adres 403 TENANT_NOT_FOUND ile döner
    |     (example.com ayrıca alıcı tarafında rezerve alan adı sayılır). Test
    |     komutunun anlamlı bir cevap verebilmesi için tenant'ınıza kayıtlı bir
    |     alan adı yazın.
    |
    */

    'defaults' => [
        'async' => false,
        'tracking' => [
            'open' => null,
            'click' => null,
        ],
        'from_email' => env('SWMAILERPRO_FROM_EMAIL'),
    ],

];

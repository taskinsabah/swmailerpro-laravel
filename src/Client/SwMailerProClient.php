<?php

namespace SabahWeb\SwMailerPro\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SabahWeb\SwMailerPro\Exceptions\ApiException;
use SabahWeb\SwMailerPro\Exceptions\ConnectionFailedException;
use SabahWeb\SwMailerPro\Exceptions\PayloadTooLargeException;
use SabahWeb\SwMailerPro\Exceptions\UnsupportedFeatureException;
use SabahWeb\SwMailerPro\Support\MeasuresBase64;

/**
 * SwMailerPro Gateway HTTP client.
 *
 * Saf HTTP katmanı — event dispatch YAPMAZ, framework-agnostic kalır.
 * Event dispatch sorumluluğu Transport ve Commands'dadır.
 */
class SwMailerProClient
{
    use MeasuresBase64;

    /**
     * Default ceiling on how long any Retry-After may ask us to wait.
     *
     * Beyond this the honest answer is to fail: sleeping a minute inside a web
     * request or a queue worker holds a process hostage for one message, and
     * the queue can retry the job far more cheaply than we can block.
     *
     * Config ile ezilebilir; bir alt sınıf da bu sabiti ezebilir.
     */
    protected const MAX_RETRY_AFTER_SECONDS = 5;

    public function __construct(
        protected readonly string $baseUrl,
        protected readonly string $apiKey,
        protected readonly int $timeout = 30,
        /** @var array{times?: int, sleep?: int} Config'den gelir; eksik anahtar normaldir. */
        protected readonly array $retry = ['times' => 2, 'sleep' => 200],
        /**
         * Separate from $timeout on purpose: a gateway that is down should be
         * reported in seconds, not after the full response budget has elapsed.
         */
        protected readonly int $connectTimeout = 10,
        /**
         * Send an Idempotency-Key with every send. The gateway keys on
         * tenant+method+path+key and replays the first response, which is what
         * makes retrying a POST safe. Off only for a gateway too old to know
         * the header — leaving it off means a retried timeout can send twice.
         */
        protected readonly bool $idempotency = true,
        /**
         * @var array<string, int> Gateway'in kendi tavanlarının kopyası. Burada
         *      erken yakalamak, reddedileceği kesin olan 20 MB'lık bir yüklemeyi
         *      hiç yapmamak demek. Bir tavanı 0 yapmak o kontrolü kapatır.
         */
        protected readonly array $limits = [],
        /**
         * @var int|null Bir Retry-After'ın bizden isteyebileceği en uzun
         *      bekleme (saniye). null ise sınıfın MAX_RETRY_AFTER_SECONDS
         *      sabiti geçerli. 0 "hiçbir Retry-After beklenmez" demektir —
         *      limits'teki 0'ın aksine tavanı KAPATMAZ.
         */
        protected readonly ?int $maxRetryAfter = null,
    ) {
    }

    /**
     * Yürürlükteki tavan. Config bir değer vermediyse sınıfın kendi sabiti.
     *
     * static:: bilerek: sabit protected, yani bir alt sınıf onu ezerek kendi
     * sabrını tanımlayabilir. Aynı ifade parametre varsayılanı olarak
     * yazılamaz — PHP derleme zamanı sabitlerinde static:: kabul etmiyor.
     */
    protected function maxRetryAfterSeconds(): int
    {
        return max(0, $this->maxRetryAfter ?? static::MAX_RETRY_AFTER_SECONDS);
    }

    /**
     * Gateway tavanlarının paket içindeki varsayılanları.
     *
     * @var array<string, int>
     */
    protected const DEFAULT_LIMITS = [
        'attachments' => 10,
        'attachment_bytes' => 10485760,
        'attachments_total_bytes' => 15728640,
        'personalizations' => 1000,
        'body_bytes' => 20971520,
        // Published config files from v1.0.0 have none of the keys below, and
        // config() merging does not reach into a nested array — so these
        // defaults are what such an application actually enforces. They stay
        // equal to the shipped config values, with one deliberate exception.
        'header_overhead_bytes' => 2048,
        // The exception. The queue ceiling only exists on a gateway that has
        // QUEUE_ASYNC_SENDS turned on, and that flag ships off — so an async
        // send of 5 MB is something some gateways accept today. A v1.0.0
        // config carries neither this key nor the env() line that overrides
        // it, so mirroring the shipped 2 MiB here would impose a new hard
        // failure on an application that cannot see the knob anywhere in its
        // own config file. 0 leaves such an application exactly as it was;
        // the shipped config turns the guard on for everyone who installs now.
        'async_payload_bytes' => 0,
        'subject_chars' => 998,
        'content_value_chars' => 5000000,
        'address_name_chars' => 256,
        'filename_chars' => 256,
    ];

    /**
     * Senkron mail gönderimi.
     * POST /api/v1/email/send → 200
     *
     * @param array<string, mixed> $payload
     * @param string|null $idempotencyKey Aynı mesajın tekrarını gateway'de tekilleştirir.
     * @return array<string, mixed> API response body
     */
    public function send(array $payload, ?string $idempotencyKey = null): array
    {
        $this->guardCapabilities($payload);
        $this->guardSize($payload);

        return $this->request('POST', '/api/v1/email/send', $payload, $idempotencyKey);
    }

    /**
     * Asenkron mail gönderimi — gateway kuyruğa alır.
     * POST /api/v1/email/send-async → 202
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendAsync(array $payload, ?string $idempotencyKey = null): array
    {
        $this->guardCapabilities($payload);
        $this->guardSize($payload);
        $this->guardQueueSize($payload);

        return $this->request('POST', '/api/v1/email/send-async', $payload, $idempotencyKey);
    }

    /**
     * Dry-run test — payload doğrulaması, gerçek gönderim yok.
     * POST /api/v1/email/send-test → 200
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendTest(array $payload, ?string $idempotencyKey = null): array
    {
        $this->guardCapabilities($payload);
        $this->guardSize($payload);

        return $this->request('POST', '/api/v1/email/send-test', $payload, $idempotencyKey);
    }

    /**
     * Gateway sağlık durumu.
     * GET /api/v1/health → 200
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        // A degraded gateway answers 503 with a perfectly good success envelope —
        // that is the answer, not an error. Treating it as one turned the health
        // command into a stack trace exactly when it had something to report.
        return $this->request('GET', '/api/v1/health', null, null, [503]);
    }

    /**
     * HTTP isteği gönderir.
     *
     * Retry stratejisi: bağlantı hataları, 5xx ve 429 — ama bir Retry-After
     * tavandan uzun bir bekleme isterse, statü ne olursa olsun beklenmez.
     * 4xx kalıcı hatalardır, tekrar denenmez.
     *
     * @param array<string, mixed>|null $data
     * @param array<int, int> $tolerate Hata sayılmayacak HTTP kodları
     * @return array<string, mixed>
     *
     * @throws ApiException API hata yanıtı
     */
    protected function request(
        string $method,
        string $uri,
        ?array $data = null,
        ?string $idempotencyKey = null,
        array $tolerate = [],
    ): array {
        $headers = [
            'X-Api-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ];

        // Computed once, before the first attempt, so every retry of THIS
        // request carries the same key — that is the whole point. A separate
        // send (including a queue worker's retry, which rebuilds the message)
        // gets its own key and is delivered as the distinct message it is.
        if ($this->idempotency && $this->isSendPath($uri)) {
            $headers['Idempotency-Key'] = $idempotencyKey ?? (string) Str::uuid();
        }

        $base = max(0, $this->retry['sleep'] ?? 200);

        $pending = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(
                max(1, $this->retry['times'] ?? 2),
                function (int $attempt, \Throwable $e) use ($base): int {
                    $retryAfter = $this->retryAfterSeconds($e);

                    // The gateway says how long it wants; guessing 200ms just
                    // burns an attempt against a limit that has not reset.
                    // min() burada emniyet kemeri: aşağıdaki when kapanışı
                    // tavanı aşan bir Retry-After'ı zaten reddediyor, ama iki
                    // kapanış birbirinden bağımsız yazıldı.
                    return $retryAfter > 0
                        ? min($retryAfter, $this->maxRetryAfterSeconds()) * 1000
                        : $base * $attempt;
                },
                function (?\Throwable $e): bool {
                    // Laravel bu kapanışa $response->toException() geçiyor ve o
                    // yalnızca 4xx/5xx için bir istisna üretir. 3xx ne başarılı
                    // ne hatalı sayıldığından null geliyor; imza null kabul
                    // etmezse paketin "SwMailerProException yakalayın"
                    // sözleşmesi ham bir TypeError ile kırılıyor.
                    if ($e === null) {
                        return false;
                    }

                    if ($e instanceof ConnectionException) {
                        // Safe to repeat only because the Idempotency-Key above
                        // means a request the gateway already accepted is not
                        // delivered twice.
                        return true;
                    }

                    if (! $e instanceof RequestException) {
                        return false;
                    }

                    if ($e->response->status() === 429) {
                        $retryAfter = $this->retryAfterSeconds($e);

                        return $retryAfter > 0 && $retryAfter <= $this->maxRetryAfterSeconds();
                    }

                    if (! $e->response->serverError()) {
                        return false;
                    }

                    // Bir 5xx'in Retry-After'ı da bağlayıcıdır: tavanı aşan bir
                    // bekleme isteği 429'daki gibi reddedilir. Başlık yoksa
                    // retryAfterSeconds() 0 döner ve normal backoff işler —
                    // 429'un aksine, başlıksız bir 5xx yine denenir.
                    return $this->retryAfterSeconds($e) <= $this->maxRetryAfterSeconds();
                },
                throw: false,
            );

        $url = rtrim($this->baseUrl, '/') . $uri;

        try {
            /** @var Response $response */
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url),
                'POST' => $pending->post($url, $data ?? []),
                'DELETE' => $pending->delete($url, $data ?? []),
                default => $pending->send($method, $url, ['json' => $data]),
            };
        } catch (ConnectionException $e) {
            throw new ConnectionFailedException(
                'SwMailerPro: gateway sunucusuna ulaşılamadı — ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (! $response->successful() && ! in_array($response->status(), $tolerate, true)) {
            throw ApiException::fromResponse($response);
        }

        $body = $response->json();

        // A proxy login page or a Cloudflare interstitial is a 200 with a body
        // that is not ours. Returning [] for it reported undelivered mail as sent.
        if (! is_array($body)) {
            throw new ApiException(
                message: 'SwMailerPro: gateway JSON yanıt döndürmedi — araya bir proxy girmiş olabilir.',
                errorCode: 'INVALID_RESPONSE',
                httpStatus: $response->status(),
            );
        }

        // Zarfın kendisi başarısız diyorsa cevap budur. Yalnızca HTTP koduna
        // bakmak, success:false taşıyan bir 200'ü gönderilmiş mail sayıyordu —
        // ve tolere edilen bir 503 için de aynı kör nokta geçerliydi.
        if (($body['success'] ?? null) === false) {
            throw ApiException::fromResponse($response);
        }

        return $body;
    }

    /**
     * Bu istemcinin desteklemediği bir yeteneği isteyen payload'ı reddeder.
     *
     * guardSize gateway'in tavanlarını kopyalar; bu ise bilerek gateway'den daha
     * katı. Gateway transactional:false'ı, çağıran kendi dkim_selector'ını
     * yollarsa kabul ediyor — bu istemci hiçbir koşulda DKIM kimliği iddia
     * etmiyor. Gerekçesi UnsupportedFeatureException docblock'unda; bu yüzden
     * guardSize'daki gibi bir tavanı 0 yapıp kapatma kaçamağı da yok.
     *
     * @param array<string, mixed> $payload
     *
     * @throws UnsupportedFeatureException
     */
    protected function guardCapabilities(array $payload): void
    {
        UnsupportedFeatureException::guardPayload($payload);
    }

    /**
     * Gateway'in reddedeceği bir payload'ı yüklemeden önce reddeder.
     *
     * @param array<string, mixed> $payload
     *
     * @throws PayloadTooLargeException
     */
    protected function guardSize(array $payload): void
    {
        $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];

        $this->assertWithin('attachments', count($attachments), 'ek sayısı');

        $personalizations = is_array($payload['personalizations'] ?? null) ? $payload['personalizations'] : [];
        $this->assertWithin('personalizations', count($personalizations), 'personalization sayısı');

        $total = 0;

        foreach ($attachments as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['content'] ?? null)) {
                continue;
            }

            $size = $this->decodedSize($attachment['content']);
            $total += $size;
            $name = is_string($attachment['filename'] ?? null) ? $attachment['filename'] : '(isimsiz)';

            $this->assertWithin('attachment_bytes', $size, "ek boyutu ({$name})");

            if (is_string($attachment['filename'] ?? null)) {
                $this->assertLengthWithin('filename_chars', $attachment['filename'], "ek dosya adı ({$name})");
            }
        }

        $this->assertWithin('attachments_total_bytes', $total, 'toplam ek boyutu');

        $this->guardTextLimits($payload);

        $this->assertWithin('body_bytes', $this->encodedSize($payload), 'mesaj boyutu');
    }

    /**
     * Kuyruğa alınamayacak kadar ağır bir mesajı yüklemeden önce reddeder.
     *
     * Gateway'de kuyruk açıkken (QUEUE_ASYNC_SENDS) /send-async gövdeyi
     * tartıyor ve QUEUE_MAX_PAYLOAD_BYTES'ı aşanı 413 PAYLOAD_TOO_LARGE ile
     * geri çeviriyor — worker bir batch'in tamamını aynı anda belleğe alıyor,
     * o yüzden tavan gövde tavanından (20 MB) çok daha alçak: varsayılan 2 MiB.
     * Bizde karşılığı olmadığı için 1.6 MB'lık bir ek base64'e şişip tamamen
     * yükleniyor ve ancak orada reddediliyordu; üstelik README'nin söz verdiği
     * PayloadTooLargeException yerine ApiException olarak.
     *
     * @param array<string, mixed> $payload
     *
     * @throws PayloadTooLargeException
     */
    protected function guardQueueSize(array $payload): void
    {
        $ceiling = $this->ceiling('async_payload_bytes');

        if ($ceiling <= 0) {
            return;
        }

        // No header overhead here, unlike the body check: the queue weighs the
        // request body and nothing else (a bare Buffer.byteLength of the
        // stringified payload). Adding the 2048-byte pad that belongs to the
        // gateway's *other* size check would make us 2 KB stricter than the
        // ceiling we claim to mirror — the same drift this guard exists to end.
        $bytes = $this->encodedSize($payload, withOverhead: false);

        if ($bytes <= $ceiling) {
            return;
        }

        throw new PayloadTooLargeException(sprintf(
            'SwMailerPro: kuyruk payload sınırı aşıyor (%s > %s). Gateway bu mesajı kuyruğa almaz; '
            . 'aynısını senkron /api/v1/email/send ile gönderin. İstek gönderilmedi.',
            $this->human($bytes),
            $this->human($ceiling),
        ));
    }

    /**
     * Gövde ağırlığıyla ilgisi olmayan, sıradan bir mailin çarptığı tavanlar.
     *
     * Hepsi gateway'in şemasında ve aşıldığında yanıt 400 VALIDATION_ERROR —
     * ama ancak gövdenin tamamı yüklendikten sonra, üstelik çağırana hangi
     * alanın suçlu olduğunu söylemeyen bir gövdeyle.
     *
     * @param array<string, mixed> $payload
     *
     * @throws PayloadTooLargeException
     */
    protected function guardTextLimits(array $payload): void
    {
        if (is_string($payload['subject'] ?? null)) {
            $this->assertLengthWithin('subject_chars', $payload['subject'], 'konu uzunluğu');
        }

        foreach ((is_array($payload['content'] ?? null) ? $payload['content'] : []) as $index => $part) {
            if (is_array($part) && is_string($part['value'] ?? null)) {
                $label = sprintf('içerik uzunluğu (bölüm %d)', is_int($index) ? $index + 1 : 1);
                $this->assertLengthWithin('content_value_chars', $part['value'], $label);
            }
        }

        foreach (['from', 'reply_to', 'envelope_from'] as $field) {
            $this->assertAddressNameWithin($payload[$field] ?? null);
        }

        $personalizations = is_array($payload['personalizations'] ?? null) ? $payload['personalizations'] : [];

        foreach ($personalizations as $personalization) {
            if (! is_array($personalization)) {
                continue;
            }

            // Personalization'ın kendi subject'i de aynı tavana tabi; gövdedeki
            // subject'e bakıp burayı atlamak, per-recipient konu kullanan bir
            // gönderimde tavanı tamamen kör bırakıyordu.
            if (is_string($personalization['subject'] ?? null)) {
                $this->assertLengthWithin('subject_chars', $personalization['subject'], 'konu uzunluğu');
            }

            $this->assertAddressNameWithin($personalization['from'] ?? null);

            foreach (['to', 'cc', 'bcc'] as $group) {
                $addresses = is_array($personalization[$group] ?? null) ? $personalization[$group] : [];

                foreach ($addresses as $address) {
                    $this->assertAddressNameWithin($address);
                }
            }
        }
    }

    /**
     * Bir adresin görünen adı tavanı aşıyorsa reddeder. Adres bir dizi değilse
     * ya da adı yoksa söylenecek bir şey yok — asıl doğrulama gateway'in.
     *
     * @throws PayloadTooLargeException
     */
    protected function assertAddressNameWithin(mixed $address): void
    {
        if (! is_array($address) || ! is_string($address['name'] ?? null)) {
            return;
        }

        $email = is_string($address['email'] ?? null) ? $address['email'] : '(adressiz)';

        $this->assertLengthWithin('address_name_chars', $address['name'], "görünen ad ({$email})");
    }

    /**
     * Gövdenin gateway'in tarttığı hâlinin bayt sayısı.
     *
     * Gateway gövdeyi aldığı gibi ölçüyor: her ek orada base64 (≈4/3) ve JSON
     * kaçışlarıyla duruyor. Çözülmüş ek baytlarını toplamak ölçümü ~%33 hafif
     * gösteriyordu — yani 15 MB'lık ekler yerelde geçiyor, gateway'de 20 MB
     * tavanına çarpıyordu ve tavan tam işe yarayacağı bantta susuyordu.
     * header_overhead_bytes, gateway'in kendi hesabına eklediği sabit başlık
     * payının kopyası; tam sınırdaki bir mesaj burada geçip orada
     * reddedilmesin diye.
     *
     * @param array<string, mixed> $payload
     */
    protected function encodedSize(array $payload, bool $withOverhead = true): int
    {
        $json = json_encode($payload);

        // Geçersiz UTF-8'de json_encode false döner. Böyle bir payload zaten
        // gönderilemiyor; hatayı kodlamayı gerçekten yapan katman versin —
        // buradan "boyut bilinmiyor" diye geçmek, tavanı yanlış bir gerekçeyle
        // patlatmaktan iyi.
        if ($json === false) {
            return 0;
        }

        if (! $withOverhead) {
            return strlen($json);
        }

        return strlen($json) + max(0, $this->ceiling('header_overhead_bytes'));
    }

    /**
     * Yürürlükteki tavan: config verdiyse o, vermediyse paketin kendi
     * varsayılanı. v1.0.0'dan kalma bir published config yeni anahtarları
     * taşımıyor ve onlar için ikinci kaynak burası.
     */
    protected function ceiling(string $limit): int
    {
        return (int) ($this->limits[$limit] ?? self::DEFAULT_LIMITS[$limit] ?? 0);
    }

    /**
     * Karakter sayısıyla ölçülen bir tavan. Bayt değil karakter, çünkü
     * gateway'in şeması da öyle sayıyor: "Ayşe" 4 karakter, 5 bayt — bayta
     * bakmak Türkçe bir konuyu tavanın altındayken reddederdi.
     *
     * @throws PayloadTooLargeException
     */
    protected function assertLengthWithin(string $limit, string $value, string $label): void
    {
        $ceiling = $this->ceiling($limit);
        $length = mb_strlen($value, 'UTF-8');

        if ($ceiling <= 0 || $length <= $ceiling) {
            return;
        }

        throw new PayloadTooLargeException(sprintf(
            'SwMailerPro: %s sınırı aşıyor (%d > %d karakter). Gateway bunu zaten reddederdi; istek gönderilmedi.',
            $label,
            $length,
            $ceiling,
        ));
    }

    /**
     * Tavanı aşan değeri, hangi tavan olduğunu söyleyerek reddeder.
     * Tavan 0 (ya da eksi) ise kontrol kapalıdır.
     */
    protected function assertWithin(string $limit, int $value, string $label): void
    {
        $ceiling = $this->ceiling($limit);

        if ($ceiling <= 0 || $value <= $ceiling) {
            return;
        }

        throw new PayloadTooLargeException(sprintf(
            'SwMailerPro: %s sınırı aşıyor (%s > %s). Gateway bunu zaten reddederdi; istek gönderilmedi.',
            $label,
            $this->human($value),
            $this->human($ceiling),
        ));
    }

    protected function human(int $value): string
    {
        return $value >= 1024 * 1024
            ? round($value / 1024 / 1024, 1) . ' MB'
            : (string) $value;
    }

    /**
     * Retry-After değeri (saniye). Başlık yoksa ya da okunamıyorsa 0.
     *
     * RFC 7231 iki biçime izin veriyor: saniye ve bir HTTP tarihi. Tarihi de
     * saniyeye çeviriyoruz ki çağıran tek bir sayıya bakarak karar versin.
     * Tavan burada uygulanmaz — 429 dalının "sunucu on dakika istedi, o zaman
     * hiç denemeyelim" diyebilmesi için ham değere ihtiyacı var.
     */
    protected function retryAfterSeconds(\Throwable $e): int
    {
        if (! $e instanceof RequestException) {
            return 0;
        }

        // header() her zaman string döner: başlık yoksa boş, iki kez
        // gönderilmişse "1, 600" diye birleşmiş. İkisi de hiçbir biçime
        // uymaz ve aşağıda kendiliğinden 0'a düşer.
        $header = trim($e->response->header('Retry-After'));

        if (is_numeric($header)) {
            return max(0, (int) $header);
        }

        $at = $this->httpDateTimestamp($header);

        // Geçmiş bir tarih "beklemeye gerek yok" demek, "vazgeç" değil.
        return $at === null ? 0 : max(0, $at - time());
    }

    /**
     * RFC 7231'in üç tarih biçimi → unix damgası, okunamıyorsa null.
     *
     * strtotime yerine sabit biçimler, üçü de ölçülmüş sebeplerle: asctime
     * saat dilimi taşımıyor ve strtotime onu sunucunun yerel saatiyle okuyor —
     * Istanbul'da tarih üç saat geriye kayıyor, yani gelecekteki bir tarih
     * geçmiş görünüp başlık sessizce düşüyor. İkincisi, "Wed," gibi yarım bir
     * metni strtotime gelecek çarşambaya çeviriyor; bozuk bir başlıktan
     * günlerce bekleme çıkmamalı. Üçüncüsü, sondaki çöpü de yutuyor.
     *
     * Gün adı bilerek '*' ile atlanıyor: RFC'de yedek bilgi, ama tutmadığında
     * ayrıştırıcı tarihi o güne ileri kaydırıyor (yanlış gün adı taşıyan bir
     * IMF-fixdate beş gün sonrasına gidiyor).
     */
    protected function httpDateTimestamp(string $value): ?int
    {
        $gmt = new \DateTimeZone('GMT');

        $formats = [
            '!*, d M Y H:i:s \G\M\T', // IMF-fixdate
            '!*, d-M-y H:i:s T',      // RFC 850 — eskimiş
            '!* M j H:i:s Y',         // asctime — eskimiş, saat dilimi yok
        ];

        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value, $gmt);

            if ($parsed === false) {
                continue;
            }

            // Biçime uyan her metin geçerli bir tarih değil: "32 Sep" ya da
            // "25:61" ayrıştırıcıyı yanıltmaz ama false da döndürmez — alanı
            // taşırıp bir uyarı bırakır. Uyarıyı okumazsak bozuk bir başlık,
            // tavanı aşan geçerli bir tarih gibi görünür ve tekrar denenebilir
            // bir 5xx'i hiç denenmeden hataya çevirir.
            $errors = \DateTimeImmutable::getLastErrors();

            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            return $parsed->getTimestamp();
        }

        return null;
    }

    /**
     * Idempotency yalnızca gönderim uçlarında anlamlı — gateway anahtarı
     * tenant+method+path ile kapsıyor, GET'lerde saklanacak bir yan etki yok.
     */
    protected function isSendPath(string $uri): bool
    {
        return str_starts_with($uri, '/api/v1/email/');
    }
}

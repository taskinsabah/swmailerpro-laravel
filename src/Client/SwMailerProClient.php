<?php

namespace SabahWeb\SwMailerPro\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SabahWeb\SwMailerPro\Exceptions\ApiException;
use SabahWeb\SwMailerPro\Exceptions\ConnectionFailedException;

/**
 * SwMailerPro Gateway HTTP client.
 *
 * Saf HTTP katmanı — event dispatch YAPMAZ, framework-agnostic kalır.
 * Event dispatch sorumluluğu Transport ve Commands'dadır.
 */
class SwMailerProClient
{
    /**
     * How long a 429's Retry-After may ask us to wait before we stop waiting.
     *
     * Beyond this the honest answer is to fail: sleeping a minute inside a web
     * request or a queue worker holds a process hostage for one message, and
     * the queue can retry the job far more cheaply than we can block.
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
    ) {
    }

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
     * Retry stratejisi: bağlantı hataları, 5xx ve — Retry-After kısa olduğu
     * sürece — 429. 4xx kalıcı hatalardır, tekrar denenmez.
     *
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     *
     * @throws ApiException API hata yanıtı
     */
    /**
     * @param array<string, mixed>|null $data
     * @param array<int, int> $tolerate Hata sayılmayacak HTTP kodları
     * @return array<string, mixed>
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
                    return $retryAfter > 0
                        ? $retryAfter * 1000
                        : $base * $attempt;
                },
                function (\Throwable $e): bool {
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

                        return $retryAfter > 0 && $retryAfter <= self::MAX_RETRY_AFTER_SECONDS;
                    }

                    return $e->response->serverError();
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

        return $body;
    }

    /**
     * Retry-After değeri (saniye). Başlık yoksa ya da okunamıyorsa 0.
     */
    protected function retryAfterSeconds(\Throwable $e): int
    {
        if (! $e instanceof RequestException) {
            return 0;
        }

        $header = $e->response->header('Retry-After');

        return is_numeric($header) ? max(0, (int) $header) : 0;
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

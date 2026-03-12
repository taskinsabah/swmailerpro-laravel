<?php

namespace SabahWeb\SwMailerPro\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SabahWeb\SwMailerPro\Exceptions\ApiException;

/**
 * SwMailerPro Gateway HTTP client.
 *
 * Saf HTTP katmanı — event dispatch YAPMAZ, framework-agnostic kalır.
 * Event dispatch sorumluluğu Transport ve Commands'dadır.
 *
 * V1'de 4 endpoint: send, sendAsync, sendTest, health.
 * V2'de suppression, templates vb. eklenir.
 */
class SwMailerProClient
{
    public function __construct(
        protected readonly string $baseUrl,
        protected readonly string $apiKey,
        protected readonly int $timeout = 30,
        /** @var array{times: int, sleep: int} */
        protected readonly array $retry = ['times' => 2, 'sleep' => 200],
    ) {
    }

    /**
     * Senkron mail gönderimi.
     * POST /api/v1/email/send → 200
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> API response body
     */
    public function send(array $payload): array
    {
        return $this->request('POST', '/api/v1/email/send', $payload);
    }

    /**
     * Asenkron mail gönderimi — gateway kuyruğa alır.
     * POST /api/v1/email/send-async → 202
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendAsync(array $payload): array
    {
        return $this->request('POST', '/api/v1/email/send-async', $payload);
    }

    /**
     * Dry-run test — payload doğrulaması, gerçek gönderim yok.
     * POST /api/v1/email/send-test → 200
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendTest(array $payload): array
    {
        return $this->request('POST', '/api/v1/email/send-test', $payload);
    }

    /**
     * Gateway sağlık durumu.
     * GET /api/v1/health → 200
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request('GET', '/api/v1/health');
    }

    /**
     * HTTP isteği gönderir.
     *
     * Retry stratejisi: Sadece 429, 5xx ve ConnectionException durumlarında tekrar dener.
     * 4xx (400, 401, 403) kalıcı hatalar — retry yapılmaz.
     *
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     *
     * @throws ApiException API hata yanıtı
     */
    protected function request(string $method, string $uri, ?array $data = null): array
    {
        $pending = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->retry(
                $this->retry['times'],
                $this->retry['sleep'],
                function (\Throwable $e, PendingRequest $request): bool {
                    if ($e instanceof RequestException) {
                        return $e->response->status() === 429 || $e->response->serverError();
                    }

                    return $e instanceof ConnectionException;
                },
                throw: false,
            );

        $url = rtrim($this->baseUrl, '/') . $uri;

        /** @var Response $response */
        $response = match (strtoupper($method)) {
            'GET' => $pending->get($url),
            'POST' => $pending->post($url, $data ?? []),
            'DELETE' => $pending->delete($url, $data ?? []),
            default => $pending->send($method, $url, ['json' => $data]),
        };

        if (! $response->successful()) {
            throw ApiException::fromResponse($response);
        }

        return $response->json() ?? [];
    }
}

<?php

namespace SabahWeb\SwMailerPro\Tests\Unit;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Exceptions\ApiException;
use SabahWeb\SwMailerPro\Tests\TestCase;

class ClientTest extends TestCase
{
    private SwMailerProClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new SwMailerProClient(
            baseUrl: 'https://test-gateway.example.com',
            apiKey: 'test-key-abc',
            timeout: 10,
            retry: ['times' => 1, 'sleep' => 0],
        );
    }

    // ─── send ───────────────────────────────────────────────────────

    #[Test]
    public function send_posts_to_correct_url_with_headers(): void
    {
        Http::fake([
            'test-gateway.example.com/api/v1/email/send' => Http::response([
                'success' => true,
                'data' => ['status' => 'queued', 'message' => 'ok', 'provider' => 'mailchannels'],
                'request_id' => 'req_123',
            ], 200),
        ]);

        $payload = ['from' => ['email' => 'a@b.com'], 'subject' => 'Hi'];

        $result = $this->client->send($payload);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://test-gateway.example.com/api/v1/email/send'
                && $request->method() === 'POST'
                && $request->header('X-Api-Key')[0] === 'test-key-abc'
                && $request->header('Accept')[0] === 'application/json'
                && $request['from']['email'] === 'a@b.com';
        });

        $this->assertTrue($result['success']);
        $this->assertEquals('req_123', $result['request_id']);
    }

    // ─── sendAsync ──────────────────────────────────────────────────

    #[Test]
    public function send_async_posts_to_async_endpoint(): void
    {
        Http::fake([
            'test-gateway.example.com/api/v1/email/send-async' => Http::response([
                'success' => true,
                'data' => ['status' => 'queued'],
                'request_id' => 'req_456',
            ], 202),
        ]);

        $result = $this->client->sendAsync(['from' => ['email' => 'x@y.com']]);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/send-async'));
        $this->assertEquals('req_456', $result['request_id']);
    }

    // ─── sendTest ───────────────────────────────────────────────────

    #[Test]
    public function send_test_posts_to_test_endpoint(): void
    {
        Http::fake([
            'test-gateway.example.com/api/v1/email/send-test' => Http::response([
                'success' => true,
                'data' => ['status' => 'validated'],
            ], 200),
        ]);

        $result = $this->client->sendTest(['from' => ['email' => 'q@w.com']]);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/send-test'));
        $this->assertEquals('validated', $result['data']['status']);
    }

    // ─── health ─────────────────────────────────────────────────────

    #[Test]
    public function health_sends_get_request(): void
    {
        Http::fake([
            'test-gateway.example.com/api/v1/health' => Http::response([
                'data' => ['status' => 'healthy', 'uptime' => 12345],
            ], 200),
        ]);

        $result = $this->client->health();

        Http::assertSent(function ($r) {
            return $r->method() === 'GET'
                && str_contains($r->url(), '/api/v1/health');
        });

        $this->assertEquals('healthy', $result['data']['status']);
    }

    // ─── Error Handling ─────────────────────────────────────────────

    #[Test]
    public function throws_api_exception_on_400(): void
    {
        Http::fake([
            'test-gateway.example.com/*' => Http::response([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'from is required'],
                'request_id' => 'req_err_1',
            ], 400),
        ]);

        try {
            $this->client->send(['invalid' => true]);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertEquals('VALIDATION_ERROR', $e->errorCode);
            $this->assertEquals(400, $e->httpStatus);
            $this->assertStringContainsString('from is required', $e->getMessage());
            $this->assertArrayHasKey('error', $e->errorBody);
        }
    }

    #[Test]
    public function throws_api_exception_on_401(): void
    {
        Http::fake([
            'test-gateway.example.com/*' => Http::response([
                'success' => false,
                'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid API key'],
            ], 401),
        ]);

        $this->expectException(ApiException::class);

        $this->client->health();
    }

    #[Test]
    public function throws_api_exception_on_500(): void
    {
        Http::fake([
            'test-gateway.example.com/*' => Http::response([
                'success' => false,
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Server error'],
            ], 500),
        ]);

        $this->expectException(ApiException::class);

        $this->client->send(['from' => ['email' => 't@t.com']]);
    }

    // ─── URL Building ───────────────────────────────────────────────

    #[Test]
    public function handles_trailing_slash_in_base_url(): void
    {
        $client = new SwMailerProClient(
            baseUrl: 'https://gw.example.com/',
            apiKey: 'key',
            timeout: 5,
            retry: ['times' => 0, 'sleep' => 0],
        );

        Http::fake([
            'gw.example.com/api/v1/health' => Http::response(['data' => ['status' => 'healthy']], 200),
        ]);

        $result = $client->health();

        Http::assertSent(fn ($r) => $r->url() === 'https://gw.example.com/api/v1/health');
    }
}

<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Exceptions\ApiException;
use SabahWeb\SwMailerPro\Tests\TestCase;

/**
 * What may be retried and what may not. A 429 is the interesting case: the
 * gateway says how long it wants to be left alone, and waiting that long inside
 * a worker is usually worse than handing the failure back.
 */
class RetryPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function client(): SwMailerProClient
    {
        return app('swmailerpro.client');
    }

    private function payload(): array
    {
        return [
            'from' => ['email' => 'sender@example.com'],
            'personalizations' => [['to' => [['email' => 'dest@example.com']]]],
            'subject' => 'Konu',
            'content' => [['type' => 'text/plain', 'value' => 'gövde']],
        ];
    }

    private function attempts(): int
    {
        return Http::recorded()->count();
    }

    #[Test]
    public function a_server_error_is_retried(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['success' => false, 'error' => ['code' => 'INTERNAL', 'message' => 'boom']], 500)
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts());
    }

    #[Test]
    public function a_client_error_is_not_retried(): void
    {
        Http::fake([
            '*' => Http::response(
                ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'subject required']],
                400,
            ),
        ]);

        try {
            $this->client()->send($this->payload());
            $this->fail('a 400 should surface as an ApiException');
        } catch (ApiException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode);
            $this->assertSame(400, $e->httpStatus);
        }

        $this->assertSame(1, $this->attempts(), 'a rejected payload will be rejected again');
    }

    #[Test]
    public function a_short_rate_limit_is_waited_out(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['success' => false, 'error' => ['code' => 'RATE_LIMIT']], 429, ['Retry-After' => '1'])
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts());
        Sleep::assertSlept(fn () => true);
    }

    #[Test]
    public function a_long_rate_limit_is_handed_back_instead_of_blocking(): void
    {
        Http::fake([
            '*' => Http::response(
                ['success' => false, 'error' => ['code' => 'RATE_LIMIT', 'message' => 'too many requests']],
                429,
                ['Retry-After' => '60'],
            ),
        ]);

        $this->expectException(ApiException::class);

        try {
            $this->client()->send($this->payload());
        } finally {
            $this->assertSame(
                1,
                $this->attempts(),
                'holding a worker for a minute costs more than failing and letting the queue retry',
            );
        }
    }
}

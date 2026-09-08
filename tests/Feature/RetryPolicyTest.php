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
    #[Test]
    public function a_short_server_error_retry_after_is_waited_out(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['success' => false, 'error' => ['code' => 'INTERNAL']], 503, ['Retry-After' => '3'])
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts());
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 3000);
    }

    #[Test]
    public function a_long_server_error_retry_after_is_handed_back_instead_of_blocking(): void
    {
        Http::fake([
            '*' => Http::response(
                ['success' => false, 'error' => ['code' => 'INTERNAL', 'message' => 'overloaded']],
                503,
                ['Retry-After' => '600'],
            ),
        ]);

        $this->expectException(ApiException::class);

        try {
            $this->client()->send($this->payload());
        } finally {
            $this->assertSame(
                1,
                $this->attempts(),
                'a 5xx that asks for ten minutes is no more worth waiting out than a 429 is',
            );
            Sleep::assertNeverSlept();
        }
    }

    #[Test]
    public function a_server_error_without_a_retry_after_still_uses_the_base_backoff(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['success' => false, 'error' => ['code' => 'INTERNAL']], 500)
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts(), 'no header means no instruction, not a refusal to retry');
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 200);
    }

    #[Test]
    public function the_ceiling_is_read_from_config(): void
    {
        config()->set('swmailerpro.client.max_retry_after', 30);

        Http::fake([
            '*' => Http::sequence()
                ->push(['success' => false, 'error' => ['code' => 'RATE_LIMIT']], 429, ['Retry-After' => '20'])
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts(), 'sabrı uygulama belirler, sınıf sabiti değil');
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 20000);
    }

    #[Test]
    public function a_zero_ceiling_waits_for_no_one(): void
    {
        config()->set('swmailerpro.client.max_retry_after', 0);

        Http::fake([
            '*' => Http::response(
                ['success' => false, 'error' => ['code' => 'RATE_LIMIT']],
                429,
                ['Retry-After' => '1'],
            ),
        ]);

        $this->expectException(ApiException::class);

        try {
            $this->client()->send($this->payload());
        } finally {
            $this->assertSame(
                1,
                $this->attempts(),
                '0 burada "tavan yok" değil, "hiç beklenmez" demektir',
            );
            Sleep::assertNeverSlept();
        }
    }

    #[Test]
    public function a_tolerated_503_is_answered_not_slept_through(): void
    {
        Http::fake([
            '*' => Http::response(
                ['success' => true, 'data' => ['status' => 'degraded']],
                503,
                ['Retry-After' => '600'],
            ),
        ]);

        $health = $this->client()->health();

        $this->assertSame('degraded', $health['data']['status']);
        $this->assertSame(
            1,
            $this->attempts(),
            'health komutu bozuk bir gateway raporlar; on dakika onu beklemez',
        );
        Sleep::assertNeverSlept();
    }
    #[Test]
    public function a_retry_after_date_is_waited_out_like_a_delay_in_seconds(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(
                    ['success' => false, 'error' => ['code' => 'RATE_LIMIT']],
                    429,
                    ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 2) . ' GMT'],
                )
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts(), 'RFC bir tarihe de izin veriyor; o da bir beklemedir');
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds > 0);
    }

    #[Test]
    public function an_obsolete_rfc_850_date_is_read_too(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(
                    ['success' => false, 'error' => ['code' => 'RATE_LIMIT']],
                    429,
                    ['Retry-After' => gmdate('l, d-M-y H:i:s', time() + 2) . ' GMT'],
                )
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts(), 'RFC üç biçim sayıyor; eskimiş olan da geçerli');
    }

    #[Test]
    public function a_far_future_retry_after_date_is_handed_back_instead_of_blocking(): void
    {
        Http::fake([
            '*' => Http::response(
                ['success' => false, 'error' => ['code' => 'INTERNAL', 'message' => 'overloaded']],
                503,
                ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 600) . ' GMT'],
            ),
        ]);

        $this->expectException(ApiException::class);

        try {
            $this->client()->send($this->payload());
        } finally {
            $this->assertSame(
                1,
                $this->attempts(),
                'tarih biçimi tavandan kaçış yolu olmamalı',
            );
            Sleep::assertNeverSlept();
        }
    }

    #[Test]
    public function an_asctime_date_is_read_as_gmt_whatever_the_app_timezone_is(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Istanbul');

        try {
            Http::fake([
                '*' => Http::sequence()
                    ->push(
                        ['success' => false, 'error' => ['code' => 'RATE_LIMIT']],
                        429,
                        ['Retry-After' => gmdate('D M j H:i:s Y', time() + 2)],
                    )
                    ->push(['success' => true, 'data' => []], 200),
            ]);

            $this->client()->send($this->payload());

            $this->assertSame(
                2,
                $this->attempts(),
                'asctime saat dilimi taşımaz; yerel saatle okunursa gelecek bir tarih geçmişe düşer',
            );
        } finally {
            date_default_timezone_set($previous);
        }
    }

    #[Test]
    public function a_date_with_the_wrong_weekday_is_still_read_by_its_date(): void
    {
        $at = time() + 2;
        $wrongDay = gmdate('D', strtotime('+1 day', $at));

        Http::fake([
            '*' => Http::sequence()
                ->push(
                    ['success' => false, 'error' => ['code' => 'RATE_LIMIT']],
                    429,
                    ['Retry-After' => $wrongDay . ', ' . gmdate('d M Y H:i:s', $at) . ' GMT'],
                )
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(
            2,
            $this->attempts(),
            'gün adı RFC\'de yedek bilgi; tutmayınca tarihi günlerce ileri kaydırmamalı',
        );
    }

    #[Test]
    public function an_unreadable_retry_after_falls_back_to_the_normal_backoff(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['success' => false, 'error' => ['code' => 'INTERNAL']], 500, ['Retry-After' => 'Wed,'])
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts(), 'yarım bir başlık, gelecek çarşambaya kadar bekleme emri değildir');
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 200);
    }

    #[Test]
    public function a_retry_after_date_in_the_past_falls_back_to_the_normal_backoff(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(
                    ['success' => false, 'error' => ['code' => 'INTERNAL']],
                    500,
                    ['Retry-After' => gmdate('D, d M Y H:i:s', time() - 60) . ' GMT'],
                )
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts(), 'geçmiş bir tarih "bekleme" demektir, "vazgeç" değil');
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 200);
    }
    #[Test]
    public function a_retry_after_date_with_an_impossible_field_is_not_a_date_at_all(): void
    {
        // "32 Eylül" bir tarih değil, ama createFromFormat onu 2 Ekim'e taşırıp
        // false yerine bir nesne döndürür. Okunmazsa bozuk başlık, tavanı aşan
        // geçerli bir tarih gibi görünür ve denenebilir bir 5xx'i öldürür.
        Http::fake([
            '*' => Http::sequence()
                ->push(
                    ['success' => false, 'error' => ['code' => 'INTERNAL']],
                    503,
                    ['Retry-After' => 'Tue, 32 ' . gmdate('M Y H:i:s', time() + 2) . ' GMT'],
                )
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts(), 'taşan bir alan, uzun bir bekleme emri değildir');
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 200);
    }

    #[Test]
    public function a_retry_after_time_that_rolls_over_is_not_a_date_either(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(
                    ['success' => false, 'error' => ['code' => 'INTERNAL']],
                    503,
                    ['Retry-After' => gmdate('D, d M Y', time()) . ' 25:61:00 GMT'],
                )
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->client()->send($this->payload());

        $this->assertSame(2, $this->attempts(), 'saat 25 de yok, dakika 61 de');
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 200);
    }
}

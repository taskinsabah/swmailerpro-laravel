<?php

namespace SabahWeb\SwMailerPro\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Tests\TestCase;

/**
 * The client retries. Without a key on the request, a retry after a timeout or
 * a 5xx can deliver the same mail twice — the gateway has no way to tell the
 * second attempt from a second message. These tests hold that key in place.
 */
class IdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function send(): void
    {
        Mail::raw('gövde', function ($message) {
            $message->to('dest@example.com')->subject('Konu');
        });
    }

    /** @return list<Request> */
    private function recorded(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])->all();
    }

    #[Test]
    public function a_send_carries_an_idempotency_key(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $this->send();

        $requests = $this->recorded();
        $this->assertCount(1, $requests);
        $this->assertNotEmpty($requests[0]->header('Idempotency-Key')[0] ?? '');
    }

    #[Test]
    public function every_attempt_at_one_message_reuses_the_same_key(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['success' => false, 'error' => ['code' => 'INTERNAL', 'message' => 'boom']], 500)
                ->push(['success' => true, 'data' => []], 200),
        ]);

        $this->send();

        $requests = $this->recorded();
        $this->assertCount(2, $requests, 'the 500 should have been retried');
        $this->assertSame(
            $requests[0]->header('Idempotency-Key'),
            $requests[1]->header('Idempotency-Key'),
            'a retry must not look like a new message to the gateway',
        );
    }

    #[Test]
    public function two_messages_get_two_different_keys(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $this->send();
        $this->send();

        $requests = $this->recorded();
        $this->assertNotSame(
            $requests[0]->header('Idempotency-Key'),
            $requests[1]->header('Idempotency-Key'),
            'two separate mails must not be deduplicated into one',
        );
    }

    #[Test]
    public function the_key_is_the_message_id_so_it_can_be_traced(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $this->send();

        $key = $this->recorded()[0]->header('Idempotency-Key')[0];

        // Symfony's generated Message-ID, not a random opaque value.
        $this->assertStringContainsString('@', $key);
    }

    #[Test]
    public function a_health_check_sends_no_key(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['status' => 'healthy']], 200)]);

        app('swmailerpro.client')->health();

        $this->assertSame([], $this->recorded()[0]->header('Idempotency-Key'));
    }
}

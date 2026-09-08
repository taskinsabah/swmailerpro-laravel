<?php

namespace SabahWeb\SwMailerPro\Tests\Unit;

use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use SabahWeb\SwMailerPro\Tests\TestCase;
use SabahWeb\SwMailerPro\Transport\SwMailerProTransport;

/**
 * The transport is registered through a closure handed to Mail::extend(), and
 * that closure reaches back into the container for config. Laravel 13 changed
 * how manager extend callbacks are bound, so what $this means inside such a
 * closure is now a version-dependent detail rather than an obvious one. These
 * tests fail loudly if resolving the mailer ever stops working, instead of the
 * failure surfacing as undelivered mail in production.
 */
class TransportRegistrationTest extends TestCase
{
    #[Test]
    public function the_mailer_resolves_our_transport(): void
    {
        $mailer = Mail::mailer('swmailerpro');

        $this->assertInstanceOf(SwMailerProTransport::class, $mailer->getSymfonyTransport());
        $this->assertSame('swmailerpro', (string) $mailer->getSymfonyTransport());
    }

    #[Test]
    public function the_transport_reads_config_written_after_the_provider_booted(): void
    {
        // Proves the closure resolves config lazily, at transport-build time —
        // not values captured when the provider booted.
        config()->set('swmailerpro.url', 'https://late-bound.example.com');

        $transport = Mail::mailer('swmailerpro')->getSymfonyTransport();

        $this->assertInstanceOf(SwMailerProTransport::class, $transport);
    }
}

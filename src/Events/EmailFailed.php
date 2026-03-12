<?php

namespace SabahWeb\SwMailerPro\Events;

use Illuminate\Foundation\Events\Dispatchable;

class EmailFailed
{
    use Dispatchable;

    public function __construct(
        /** @var array<string, mixed> Gönderilemeyen payload */
        public readonly array $payload,
        /** @var \Throwable Hata detayı */
        public readonly \Throwable $exception,
    ) {
    }
}

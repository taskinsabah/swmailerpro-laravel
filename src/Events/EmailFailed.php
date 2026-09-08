<?php

namespace SabahWeb\SwMailerPro\Events;


class EmailFailed
{

    public function __construct(
        /** @var array<string, mixed> Gönderilemeyen payload */
        public readonly array $payload,
        /** @var \Throwable Hata detayı */
        public readonly \Throwable $exception,
    ) {
    }
}

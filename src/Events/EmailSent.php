<?php

namespace SabahWeb\SwMailerPro\Events;

use Illuminate\Foundation\Events\Dispatchable;

class EmailSent
{
    use Dispatchable;

    public function __construct(
        /** @var array<string, mixed> Gönderilen payload */
        public readonly array $payload,
        /** @var array<string, mixed> API yanıtı */
        public readonly array $response,
        /** @var string|null Gateway request ID */
        public readonly ?string $requestId = null,
    ) {
    }
}

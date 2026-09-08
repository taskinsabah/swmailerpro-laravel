<?php

namespace SabahWeb\SwMailerPro\Exceptions;

use Illuminate\Http\Client\Response;

class ApiException extends SwMailerProException
{
    /**
     * @param array<string, mixed>|null $errorBody Gateway'in döndürdüğü hata gövdesi
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly ?array $errorBody = null,
        ?\Throwable $previous = null,
        /** @var string|null Gateway request ID — hata gateway loglarında bununla bulunur. */
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * Gateway API yanıtından ApiException oluşturur.
     *
     * Beklenen JSON formatı:
     * { "success": false, "error": { "code": "...", "message": "..." }, "request_id": "..." }
     */
    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $errorMsg = $body['error']['message'] ?? $response->body();
        $errorCode = $body['error']['code'] ?? 'UNKNOWN';

        $requestId = is_array($body) && is_string($body['request_id'] ?? null) ? $body['request_id'] : null;

        return new self(
            message: $requestId !== null
                ? "SwMailerPro API Error [{$errorCode}]: {$errorMsg} (request_id: {$requestId})"
                : "SwMailerPro API Error [{$errorCode}]: {$errorMsg}",
            errorCode: $errorCode,
            httpStatus: $response->status(),
            errorBody: is_array($body) ? $body : null,
            requestId: $requestId,
        );
    }
}

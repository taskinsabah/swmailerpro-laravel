<?php

namespace SabahWeb\SwMailerPro\Exceptions;

use Illuminate\Http\Client\Response;

class ApiException extends SwMailerProException
{
    /**
     * @param array<string, mixed>|null $errorBody
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly ?array $errorBody = null,
        ?\Throwable $previous = null,
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

        return new self(
            message: "SwMailerPro API Error [{$errorCode}]: {$errorMsg}",
            errorCode: $errorCode,
            httpStatus: $response->status(),
            errorBody: is_array($body) ? $body : null,
        );
    }
}

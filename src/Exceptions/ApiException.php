<?php

namespace SabahWeb\SwMailerPro\Exceptions;

use Illuminate\Http\Client\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Gateway yanıt verdi ama hata döndü.
 *
 * TransportExceptionInterface bilerek burada: Symfony'nin failover ve
 * round-robin transport'ları yalnızca bu arayüzü yakalıyor, dolayısıyla arayüz
 * olmadan MAIL_MAILER=failover yedeğe hiç geçmiyordu. Yerel retlerde
 * (PayloadTooLarge, UnsupportedFeature, ConfigurationException) arayüz
 * KASITLI olarak yok: onlar "bu mesaj gönderilmemeli" diyor, yedeğe düşmek
 * o kararın etrafından dolaşıp gateway'in reddettiği maili SMTP'den yollamak
 * olurdu.
 */
class ApiException extends SwMailerProException implements TransportExceptionInterface
{
    use TransportFailure;

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
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

        // Şema garanti değil: araya giren bir proxy HTML döndürüyor, bir
        // sağlayıcının ham gövdesi error.code'a dizi koyabiliyor. Skaler
        // olmayan bir değeri metne gömmek "Array to string conversion"
        // fırlatıyordu; doğan ErrorException bu paketin hiyerarşisinin dışında
        // kaldığı için hem "SwMailerProException yakalayın" sözleşmesini hem de
        // TransportExceptionInterface'e bağlı failover'ı kırıyordu.
        // Kullanılamayan bir kod UNKNOWN, kullanılamayan bir mesaj ham gövde —
        // ikisi de alan hiç yokken zaten uygulanan davranış.
        $errorCode = is_scalar($error['code'] ?? null) ? (string) $error['code'] : 'UNKNOWN';
        $errorMsg = is_scalar($error['message'] ?? null) ? (string) $error['message'] : $response->body();

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

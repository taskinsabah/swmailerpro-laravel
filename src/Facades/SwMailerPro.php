<?php

namespace SabahWeb\SwMailerPro\Facades;

use Illuminate\Support\Facades\Facade;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;

/**
 * SwMailerPro Facade — doğrudan gateway API erişimi.
 *
 * Bu facade raw API payload'ı ile çalışır. Laravel Mail akışından bağımsızdır.
 * Mail::to()->send() ve Mail::to()->queue() için ayrıca mail transport kullanılır.
 *
 * Kullanım:
 *   SwMailerPro::send($payload)      — senkron gönderim
 *   SwMailerPro::sendAsync($payload)  — async gönderim (gateway kuyruğu)
 *   SwMailerPro::sendTest($payload)   — dry-run doğrulama
 *   SwMailerPro::health()             — gateway sağlık kontrolü
 *
 * @method static array send(array $payload) Senkron mail gönderimi (POST /api/v1/email/send)
 * @method static array sendAsync(array $payload) Asenkron gönderim (POST /api/v1/email/send-async)
 * @method static array sendTest(array $payload) Dry-run test (POST /api/v1/email/send-test)
 * @method static array health() Gateway sağlık durumu (GET /api/v1/health)
 *
 * @see SwMailerProClient
 */
class SwMailerPro extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'swmailerpro.client';
    }
}

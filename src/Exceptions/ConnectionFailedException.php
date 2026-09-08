<?php

namespace SabahWeb\SwMailerPro\Exceptions;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Gateway sunucusuna hiç ulaşılamadı: DNS, TLS, bağlantı ya da yanıt zaman aşımı.
 *
 * Illuminate'in ConnectionException'ı bu paketin hiyerarşisinin dışında kaldığı için,
 * dokümanda "SwMailerProException yakalayın" diyen bir uygulama ulaşılamayan bir
 * gateway hatasını yakalayamıyordu. Artık yakalayabiliyor.
 *
 * Failover'ın var olma sebebi tam olarak bu durum, o yüzden Symfony'nin transport
 * sözleşmesini uyguluyor: gateway'e ulaşılamıyorsa yedek taşıyıcı denenmeli.
 */
class ConnectionFailedException extends SwMailerProException implements TransportExceptionInterface
{
    use TransportFailure;
}

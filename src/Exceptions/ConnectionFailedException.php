<?php

namespace SabahWeb\SwMailerPro\Exceptions;

/**
 * Gateway sunucusuna hiç ulaşılamadı: DNS, TLS, bağlantı ya da yanıt zaman aşımı.
 *
 * Illuminate'in ConnectionException'ı bu paketin hiyerarşisinin dışında kaldığı için,
 * dokümanda "SwMailerProException yakalayın" diyen bir uygulama ulaşılamayan bir
 * gateway hatasını yakalayamıyordu. Artık yakalayabiliyor.
 */
class ConnectionFailedException extends SwMailerProException
{
}

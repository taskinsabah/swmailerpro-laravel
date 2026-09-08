<?php

namespace SabahWeb\SwMailerPro\Exceptions;

/**
 * Payload, gateway kabul etmeden önce yerelde reddedildi.
 *
 * Gateway bu sınırları zaten uyguluyor — ama ancak gövdenin tamamı yüklendikten
 * sonra. 20 MB yükleyip 400 almak yavaş bir bağlantıda pahalıdır ve kuyruktaki
 * bir job için hiçbir zaman düzelmeyecek bir hatadır.
 */
class PayloadTooLargeException extends SwMailerProException
{
}

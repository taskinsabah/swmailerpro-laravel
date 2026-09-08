<?php

namespace SabahWeb\SwMailerPro\Exceptions;

/**
 * Symfony'nin TransportExceptionInterface sözleşmesinin gövdesi.
 *
 * Arayüz yalnızca iki metot istiyor ama biri şart: RoundRobinTransport, yedeğe
 * geçerken topladığı hataların üstünde getDebug() çağırıyor. Metot yoksa
 * failover yolu fatal ile bitiyor — yani arayüzü eklemek onu düzeltmek yerine
 * daha beter kırıyor.
 */
trait TransportFailure
{
    private string $transportDebug = '';

    public function getDebug(): string
    {
        return $this->transportDebug;
    }

    public function appendDebug(string $debug): void
    {
        $this->transportDebug .= $debug;
    }
}

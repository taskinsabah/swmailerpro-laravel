<?php

namespace SabahWeb\SwMailerPro\Support;

/**
 * base64 metninin çözülmüş bayt karşılığı — decode etmeden, bellek harcamadan.
 *
 * İki yerde ölçülüyor: Client tavanları uygularken, Transport ise ekin
 * kendisini event'ten çıkarıp yerine boyutunu yazarken. Aynı sayıyı iki ayrı
 * aritmetikle üretmek, istemcinin reddettiği bir ekin event'te başka bir
 * boyutla görünmesi demekti.
 */
trait MeasuresBase64
{
    /**
     * Boşluk temizliği MIME'a göre satırlara bölünmüş base64 için: RFC 2045 76
     * karakterde bir CRLF koyar ve o baytlar eke ait değildir. Temizlik ayrıca
     * dolgunun ardından gelen bir satır sonunun '=' işaretlerini gizlemesini
     * engelliyor.
     */
    protected function decodedSize(string $base64): int
    {
        $clean = preg_replace('/\s+/', '', $base64) ?? $base64;
        $padding = substr_count(substr($clean, -2), '=');

        return max(0, intdiv(strlen($clean) * 3, 4) - $padding);
    }
}

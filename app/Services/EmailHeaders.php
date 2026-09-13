<?php

namespace App\Services;

class EmailHeaders
{
    public function decode(string $value): string
    {
        if (preg_match('/=\?[^?\s]+\?[bq]\?[^?]*\?=/i', $value)) {
            $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                $value = $decoded;
            }
        }

        return trim(preg_replace('/[\r\n\x00]+[\t ]*/', ' ', $value));
    }
}

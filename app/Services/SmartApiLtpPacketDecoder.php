<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class SmartApiLtpPacketDecoder
{
    /**
     * @return array{token: string, price: float, time: CarbonImmutable}|null
     */
    public function decode(string $packet): ?array
    {
        if (strlen($packet) < 51 || ord($packet[0]) !== 1 || ord($packet[1]) !== 2) {
            return null;
        }

        $token = rtrim(substr($packet, 2, 25), "\0");
        $timestampMilliseconds = unpack('Pvalue', substr($packet, 35, 8))['value'] ?? 0;
        $priceInPaise = unpack('Pvalue', substr($packet, 43, 8))['value'] ?? 0;

        if ($token === '' || $timestampMilliseconds < 1 || $priceInPaise < 1) {
            return null;
        }

        return [
            'token' => $token,
            'price' => $priceInPaise / 100,
            'time' => CarbonImmutable::createFromTimestampMs((int) $timestampMilliseconds, 'Asia/Kolkata'),
        ];
    }
}

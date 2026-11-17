<?php

namespace Tests\Unit\Services;

use App\Services\SmartApiLtpPacketDecoder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SmartApiLtpPacketDecoderTest extends TestCase
{
    public function test_nfo_ltp_packet_returns_its_token_price_and_exchange_time(): void
    {
        $timestamp = CarbonImmutable::parse('2026-11-17 10:00:25', 'Asia/Kolkata')->valueOf();
        $packet = chr(1)
            .chr(2)
            .str_pad('61471', 25, "\0")
            .str_repeat("\0", 8)
            .pack('P', $timestamp)
            .pack('P', 23450);

        $tick = (new SmartApiLtpPacketDecoder)->decode($packet);

        $this->assertSame('61471', $tick['token']);
        $this->assertSame(234.5, $tick['price']);
        $this->assertSame('2026-11-17T10:00:25+05:30', $tick['time']->toIso8601String());
    }

    public function test_non_nfo_or_non_ltp_packets_are_ignored(): void
    {
        $decoder = new SmartApiLtpPacketDecoder;
        $wrongExchangePacket = chr(1).chr(1).str_repeat("\0", 49);
        $wrongModePacket = chr(2).chr(2).str_repeat("\0", 49);

        $this->assertSame(null, $decoder->decode($wrongExchangePacket));
        $this->assertSame(null, $decoder->decode($wrongModePacket));
    }
}

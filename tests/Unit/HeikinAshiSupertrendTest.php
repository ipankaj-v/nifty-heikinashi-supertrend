<?php

namespace Tests\Unit;

use App\Services\HeikinAshiSupertrend;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HeikinAshiSupertrendTest extends TestCase
{
    #[Test]
    public function it_waits_for_enough_candles_before_calculating_supertrend(): void
    {
        $candles = [
            ['2026-11-17T09:15:00+05:30', 100, 102, 99, 101],
            ['2026-11-17T09:16:00+05:30', 101, 103, 100, 102],
        ];

        $result = (new HeikinAshiSupertrend)->analyze($candles, 2, 1);

        $this->assertNull($result);
    }

    #[Test]
    public function it_reports_a_bearish_flip_and_an_additive_reference_level(): void
    {
        $candles = [
            ['2026-11-17T09:15:00+05:30', 100, 101, 99, 100],
            ['2026-11-17T09:16:00+05:30', 100, 102, 99, 101],
            ['2026-11-17T09:17:00+05:30', 101, 103, 100, 102],
            ['2026-11-17T09:18:00+05:30', 102, 104, 101, 103],
            ['2026-11-17T09:19:00+05:30', 103, 104, 70, 71],
        ];

        $result = (new HeikinAshiSupertrend)->analyze($candles, 2, 0.2, 0.10);

        $this->assertNotNull($result);
        $this->assertSame(-1, $result['direction']);
        $this->assertSame(1, $result['previous_direction']);
        $this->assertEqualsWithDelta(90.69375, $result['supertrend_price'], 0.0001);
        $this->assertEqualsWithDelta(71.071, $result['reference_price'], 0.001);
    }

    #[Test]
    public function it_flips_bullish_after_the_heikin_ashi_close_crosses_the_upper_band(): void
    {
        $candles = [
            ['2026-11-17T09:15:00+05:30', 100, 101, 99, 100],
            ['2026-11-17T09:20:00+05:30', 100, 101, 99, 100],
            ['2026-11-17T09:25:00+05:30', 100, 100.5, 99.5, 100.2],
            ['2026-11-17T09:30:00+05:30', 100.2, 102, 100, 102],
        ];

        $result = (new HeikinAshiSupertrend)->analyze($candles, 2, 0.2, 0.10);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['direction']);
        $this->assertSame(-1, $result['previous_direction']);
        $this->assertEqualsWithDelta(100.65, $result['supertrend_price'], 0.0001);
        $this->assertEqualsWithDelta(102.102, $result['reference_price'], 0.000001);
    }
}

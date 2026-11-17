<?php

namespace Tests\Unit\Services;

use App\Services\FiveMinuteCandleAggregator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FiveMinuteCandleAggregatorTest extends TestCase
{
    public function test_ticks_form_five_minute_ohlc_and_close_when_the_next_period_starts(): void
    {
        $aggregator = new FiveMinuteCandleAggregator;

        $this->assertSame(null, $aggregator->addTick(CarbonImmutable::parse('2026-11-17 09:15:05', 'Asia/Kolkata'), 100.0));
        $this->assertSame(null, $aggregator->addTick(CarbonImmutable::parse('2026-11-17 09:17:35', 'Asia/Kolkata'), 103.0));
        $this->assertSame(null, $aggregator->addTick(CarbonImmutable::parse('2026-11-17 09:19:58', 'Asia/Kolkata'), 99.0));

        $closedCandle = $aggregator->addTick(CarbonImmutable::parse('2026-11-17 09:20:01', 'Asia/Kolkata'), 101.0);

        $this->assertSame([
            '2026-11-17T09:15:00+05:30',
            100.0,
            103.0,
            99.0,
            99.0,
            0,
        ], $closedCandle);
    }

    public function test_out_of_order_ticks_do_not_change_the_current_candle(): void
    {
        $aggregator = new FiveMinuteCandleAggregator;
        $aggregator->addTick(CarbonImmutable::parse('2026-11-17 09:15:05', 'Asia/Kolkata'), 100.0);
        $aggregator->addTick(CarbonImmutable::parse('2026-11-17 09:17:35', 'Asia/Kolkata'), 103.0);

        $aggregator->addTick(CarbonImmutable::parse('2026-11-17 09:14:59', 'Asia/Kolkata'), 1.0);

        $this->assertSame([
            '2026-11-17T09:15:00+05:30',
            100.0,
            103.0,
            100.0,
            103.0,
            0,
        ], $aggregator->current());
    }
}

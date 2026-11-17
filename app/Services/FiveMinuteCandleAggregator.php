<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class FiveMinuteCandleAggregator
{
    public const INTERVAL_MINUTES = 5;

    /**
     * @var array<int, string|int|float>
     */
    private array $currentCandle = [];

    /**
     * @return array<int, string|int|float>|null
     */
    public function addTick(CarbonImmutable $time, float $price): ?array
    {
        $candleStart = $time->startOfMinute()->subMinutes($time->minute % self::INTERVAL_MINUTES);
        $minuteKey = $candleStart->getTimestamp();

        if ($this->currentCandle === []) {
            $this->startCandle($candleStart, $minuteKey, $price);

            return null;
        }

        $currentMinute = (int) $this->currentCandle[6];

        if ($minuteKey < $currentMinute) {
            return null;
        }

        if ($minuteKey === $currentMinute) {
            $this->currentCandle[2] = max((float) $this->currentCandle[2], $price);
            $this->currentCandle[3] = min((float) $this->currentCandle[3], $price);
            $this->currentCandle[4] = $price;

            return null;
        }

        $closedCandle = array_slice($this->currentCandle, 0, 6);
        $this->startCandle($candleStart, $minuteKey, $price);

        return $closedCandle;
    }

    /**
     * @return array<int, string|int|float>
     */
    public function current(): array
    {
        return array_slice($this->currentCandle, 0, 6);
    }

    private function startCandle(CarbonImmutable $minute, int $minuteKey, float $price): void
    {
        $this->currentCandle = [
            $minute->toIso8601String(),
            $price,
            $price,
            $price,
            $price,
            0,
            $minuteKey,
        ];
    }
}

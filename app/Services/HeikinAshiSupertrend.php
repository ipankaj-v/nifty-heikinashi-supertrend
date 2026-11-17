<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class HeikinAshiSupertrend
{
    /**
     * @param  list<array<int, string|int|float|null>>  $candles
     * @return array{direction: int, previous_direction: int, signal_time: CarbonImmutable, close: float, supertrend_price: float, reference_price: float}|null
     */
    public function analyze(array $candles, int $atrPeriod = 10, float $multiplier = 3.0, float $referenceOffsetPercent = 0.10): ?array
    {
        if ($atrPeriod < 1 || $multiplier <= 0 || $referenceOffsetPercent < 0) {
            throw new InvalidArgumentException('Supertrend settings must be positive.');
        }

        if (count($candles) < $atrPeriod + 2) {
            return null;
        }

        $heikinAshi = [];

        foreach ($candles as $index => $candle) {
            if (count($candle) < 5 || ! is_numeric($candle[1]) || ! is_numeric($candle[2]) || ! is_numeric($candle[3]) || ! is_numeric($candle[4])) {
                throw new InvalidArgumentException('Angel One returned a malformed candle.');
            }

            $open = (float) $candle[1];
            $high = (float) $candle[2];
            $low = (float) $candle[3];
            $close = (float) $candle[4];
            $haClose = ($open + $high + $low + $close) / 4;
            $haOpen = $index === 0
                ? ($open + $close) / 2
                : ($heikinAshi[$index - 1]['open'] + $heikinAshi[$index - 1]['close']) / 2;

            $heikinAshi[] = [
                'open' => $haOpen,
                'high' => max($high, $haOpen, $haClose),
                'low' => min($low, $haOpen, $haClose),
                'close' => $haClose,
                'time' => (string) $candle[0],
            ];
        }

        $trueRanges = [];

        foreach ($heikinAshi as $index => $candle) {
            $trueRanges[] = $index === 0
                ? $candle['high'] - $candle['low']
                : max(
                    $candle['high'] - $candle['low'],
                    abs($candle['high'] - $heikinAshi[$index - 1]['close']),
                    abs($candle['low'] - $heikinAshi[$index - 1]['close']),
                );
        }

        $seedIndex = $atrPeriod - 1;
        $atr = array_sum(array_slice($trueRanges, 0, $atrPeriod)) / $atrPeriod;
        $middle = ($heikinAshi[$seedIndex]['high'] + $heikinAshi[$seedIndex]['low']) / 2;
        $upperBand = $middle + ($multiplier * $atr);
        $lowerBand = $middle - ($multiplier * $atr);
        $directions = array_fill(0, count($candles), null);
        $directions[$seedIndex] = -1;

        for ($index = $seedIndex + 1; $index < count($heikinAshi); $index++) {
            $atr = (($atr * ($atrPeriod - 1)) + $trueRanges[$index]) / $atrPeriod;
            $middle = ($heikinAshi[$index]['high'] + $heikinAshi[$index]['low']) / 2;
            $basicUpper = $middle + ($multiplier * $atr);
            $basicLower = $middle - ($multiplier * $atr);
            $previousClose = $heikinAshi[$index - 1]['close'];
            $upperBand = $basicUpper < $upperBand || $previousClose > $upperBand ? $basicUpper : $upperBand;
            $lowerBand = $basicLower > $lowerBand || $previousClose < $lowerBand ? $basicLower : $lowerBand;
            $previousDirection = $directions[$index - 1];

            $directions[$index] = $previousDirection === 1
                ? ($heikinAshi[$index]['close'] < $lowerBand ? -1 : 1)
                : ($heikinAshi[$index]['close'] > $upperBand ? 1 : -1);
        }

        $lastIndex = count($directions) - 1;
        $direction = $directions[$lastIndex];
        $previousDirection = $directions[$lastIndex - 1];

        if ($direction === null || $previousDirection === null) {
            return null;
        }

        $close = (float) $candles[$lastIndex][4];
        $offset = $referenceOffsetPercent / 100;

        return [
            'direction' => $direction,
            'previous_direction' => $previousDirection,
            'signal_time' => CarbonImmutable::parse($heikinAshi[$lastIndex]['time']),
            'close' => $close,
            'supertrend_price' => $direction === 1 ? $lowerBand : $upperBand,
            'reference_price' => $close * (1 + $offset),
        ];
    }
}

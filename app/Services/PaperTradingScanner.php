<?php

namespace App\Services;

use App\Models\PaperAlert;
use App\Models\PaperTrade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaperTradingScanner
{
    private const PENDING_ENTRY_CACHE_KEY = 'angelone.pending_entry';

    private const OPEN_TRADE_CACHE_KEY = 'angelone.open_trade_state';

    private const ENTRY_PRICE_ATTEMPT_CACHE_KEY = 'angelone.paper_entry_price_attempt';

    private const EXIT_PRICE_ATTEMPT_CACHE_KEY = 'angelone.paper_exit_price_attempt';

    private const FLIP_EVENTS_CACHE_KEY = 'angelone.flip_events';

    private const FLIP_EVENTS_LIMIT = 50;

    public function __construct(
        private readonly AngelOneClient $angelOne,
        private readonly HeikinAshiSupertrend $indicator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scan(): array
    {
        if (! $this->angelOne->isConfigured()) {
            throw new RuntimeException('Complete the Angel One settings in the server .env file first.');
        }

        if ($this->angelOne->isRateLimited()) {
            return [
                'status' => 'rate_limited',
                'retry_at' => $this->angelOne->rateLimitRetryAt(),
            ];
        }

        $now = CarbonImmutable::now(config('angelone.timezone'));

        if ($now->isWeekend() || $now->format('H:i') < '09:15' || $now->format('H:i') > '15:30') {
            return ['status' => 'market_closed'];
        }

        $instruments = $this->angelOne->instruments();
        $future = $this->nearestInstrument($instruments, 'FUTIDX', $now);
        $closedCandles = $this->closedMarketCandles((string) $future['token'], $now);

        return $this->processClosedCandles($closedCandles, $future, $now);
    }

    /**
     * @param  list<array<int, string|int|float|null>>  $closedCandles
     * @param  array<string, mixed>  $future
     * @return array<string, mixed>
     */
    public function processClosedCandles(
        array $closedCandles,
        array $future,
        CarbonImmutable $now,
        bool $allowSignalProcessing = true,
    ): array {
        Cache::put('angelone.last_scan_at', $now->toIso8601String(), now()->addDay());

        $analysis = $this->indicator->analyze(
            $closedCandles,
            (int) config('angelone.atr_period'),
            (float) config('angelone.supertrend_multiplier'),
            (float) config('angelone.reference_offset_percent'),
        );

        if ($analysis === null) {
            return ['status' => 'warming_up'];
        }

        $snapshot = [
            'futures_symbol' => $future['symbol'],
            'futures_token' => (string) $future['token'],
            'futures_price' => $analysis['close'],
            'supertrend_price' => $analysis['supertrend_price'],
            'reference_price' => $analysis['reference_price'],
            'direction' => $analysis['direction'] === 1 ? 'bullish' : 'bearish',
            'candle_time' => $analysis['signal_time']->toIso8601String(),
        ];
        Cache::put('angelone.latest_snapshot', $snapshot, now()->addDay());

        if (! $allowSignalProcessing || $analysis['direction'] === $analysis['previous_direction']) {
            return ['status' => 'no_signal', 'snapshot' => $snapshot];
        }

        $signalTime = $analysis['signal_time'];
        $direction = $analysis['direction'] === -1 ? 'bearish' : 'bullish';
        $optionType = $direction === 'bearish' ? 'CE' : 'PE';
        $this->recordSupertrendFlip(
            $direction,
            $optionType,
            $signalTime,
            $analysis['close'],
            $analysis['supertrend_price'],
            $analysis['reference_price'],
            $now,
        );

        if ($this->openTradeState() !== null) {
            Cache::forget(self::PENDING_ENTRY_CACHE_KEY);

            return ['status' => 'position_open', 'snapshot' => $snapshot];
        }

        $offset = (float) config('angelone.reference_offset_percent') / 100;
        $entryTriggerPrice = round($analysis['close'] * ($direction === 'bearish' ? 1 - $offset : 1 + $offset), 2);
        $pendingEntry = [
            'direction' => $direction,
            'option_type' => $optionType,
            'futures_symbol' => $future['symbol'],
            'futures_token' => (string) $future['token'],
            'futures_price' => $analysis['close'],
            'reference_price' => $analysis['reference_price'],
            'entry_trigger_price' => $entryTriggerPrice,
            'signal_time' => $signalTime->toIso8601String(),
        ];
        Cache::forget(self::ENTRY_PRICE_ATTEMPT_CACHE_KEY);
        Cache::put(self::PENDING_ENTRY_CACHE_KEY, $pendingEntry, now()->addHours(12));

        return [
            'status' => 'pending_entry',
            'direction' => $direction,
            'option_type' => $optionType,
            'entry_trigger_price' => $entryTriggerPrice,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  list<array<int, string|int|float|null>>  $closedCandles
     * @param  array<string, mixed>  $future
     * @return array<string, mixed>
     */
    public function processClosedCandlesSince(
        array $closedCandles,
        array $future,
        CarbonImmutable $now,
        CarbonImmutable $from,
    ): array {
        $result = null;

        foreach ($closedCandles as $index => $candle) {
            $candleTime = CarbonImmutable::parse((string) $candle[0], config('angelone.timezone'));

            if ($candleTime->lessThan($from)) {
                continue;
            }

            $result = $this->processClosedCandles(
                array_slice($closedCandles, 0, $index + 1),
                $future,
                $now,
            );
        }

        if ($result !== null) {
            return $result;
        }

        return $this->processClosedCandles($closedCandles, $future, $now, false);
    }

    /**
     * @param  array<string, mixed>  $future
     * @param  list<array<string, mixed>>  $instruments
     * @return array<string, mixed>
     */
    public function processFutureTick(
        float $futuresPrice,
        CarbonImmutable $tickTime,
        array $future,
        array $instruments,
    ): array {
        $openTrade = $this->openTradeState();

        if ($openTrade !== null) {
            $targetReached = $openTrade['direction'] === 'CE'
                ? $futuresPrice <= (float) $openTrade['target_price']
                : $futuresPrice >= (float) $openTrade['target_price'];

            if (! $targetReached) {
                return ['status' => 'position_open'];
            }

            if ($this->angelOne->isRateLimited()) {
                return ['status' => 'rate_limited', 'retry_at' => $this->angelOne->rateLimitRetryAt()];
            }

            if (! Cache::add(self::EXIT_PRICE_ATTEMPT_CACHE_KEY, true, now()->addSeconds(15))) {
                return ['status' => 'retry_wait'];
            }

            $trade = PaperTrade::query()->findOrFail($openTrade['id']);

            if ($trade->status !== 'open') {
                Cache::forget(self::EXIT_PRICE_ATTEMPT_CACHE_KEY);
                Cache::put(self::OPEN_TRADE_CACHE_KEY, [], now()->addDay());

                return ['status' => 'no_action'];
            }

            $optionExitPrice = $this->angelOne->ltp('NFO', $trade->symbol, $trade->token);

            DB::transaction(function () use ($trade, $futuresPrice, $optionExitPrice, $tickTime): void {
                $trade->update([
                    'status' => 'closed',
                    'exit_price' => $optionExitPrice,
                    'exit_futures_price' => $futuresPrice,
                    'realized_pnl' => round(((float) $trade->entry_price - $optionExitPrice) * $trade->quantity, 2),
                    'exit_time' => $tickTime,
                    'exit_reason' => 'NIFTY futures reached the 0.10% favorable target',
                ]);
            });

            Cache::forget(self::EXIT_PRICE_ATTEMPT_CACHE_KEY);
            Cache::put(self::OPEN_TRADE_CACHE_KEY, [], now()->addDay());

            return [
                'status' => 'exit',
                'trade_id' => $trade->id,
                'option_symbol' => $trade->symbol,
                'option_exit_price' => $optionExitPrice,
                'futures_exit_price' => $futuresPrice,
            ];
        }

        $pendingEntry = Cache::get(self::PENDING_ENTRY_CACHE_KEY);

        if (! is_array($pendingEntry)) {
            return ['status' => 'no_action'];
        }

        $signalTime = CarbonImmutable::parse($pendingEntry['signal_time'], config('angelone.timezone'));

        if (! $signalTime->isSameDay($tickTime)) {
            Cache::forget(self::PENDING_ENTRY_CACHE_KEY);
            Cache::forget(self::ENTRY_PRICE_ATTEMPT_CACHE_KEY);

            return ['status' => 'no_action'];
        }

        $entryReached = $pendingEntry['direction'] === 'bearish'
            ? $futuresPrice <= (float) $pendingEntry['entry_trigger_price']
            : $futuresPrice >= (float) $pendingEntry['entry_trigger_price'];

        if (! $entryReached) {
            return ['status' => 'pending_entry', 'pending_entry' => $pendingEntry];
        }

        if ($this->angelOne->isRateLimited()) {
            return ['status' => 'rate_limited', 'retry_at' => $this->angelOne->rateLimitRetryAt()];
        }

        if (! Cache::add(self::ENTRY_PRICE_ATTEMPT_CACHE_KEY, true, now()->addSeconds(15))) {
            return ['status' => 'retry_wait'];
        }

        $options = $this->atmOptions($instruments, $futuresPrice, $tickTime);
        $option = $options[$pendingEntry['option_type'] === 'CE' ? 'ce' : 'pe'];
        $optionPrice = $this->angelOne->ltp('NFO', (string) $option['symbol'], (string) $option['token']);
        $offset = (float) config('angelone.reference_offset_percent') / 100;
        $targetPrice = round($futuresPrice * ($pendingEntry['direction'] === 'bearish' ? 1 - $offset : 1 + $offset), 2);

        $alert = DB::transaction(function () use ($pendingEntry, $signalTime, $future, $option, $options, $optionPrice, $futuresPrice, $targetPrice, $tickTime): PaperAlert {
            $existingAlert = PaperAlert::query()
                ->where('signal_time', $signalTime)
                ->where('direction', $pendingEntry['direction'])
                ->first();

            if ($existingAlert !== null) {
                return $existingAlert;
            }

            $trade = PaperTrade::query()->create([
                'direction' => $pendingEntry['option_type'],
                'symbol' => (string) $option['symbol'],
                'token' => (string) $option['token'],
                'expiry' => $options['expiry']->format('Y-m-d'),
                'strike' => $options['strike'],
                'quantity' => max(1, (int) config('angelone.paper_lots')) * (int) $option['lotsize'],
                'entry_price' => $optionPrice,
                'entry_futures_price' => $futuresPrice,
                'futures_target_price' => $targetPrice,
                'signal_time' => $signalTime,
                'entry_time' => $tickTime,
                'status' => 'open',
            ]);

            return PaperAlert::query()->create([
                'direction' => $pendingEntry['direction'],
                'futures_symbol' => (string) $future['symbol'],
                'futures_token' => (string) $future['token'],
                'futures_price' => (float) $pendingEntry['futures_price'],
                'reference_price' => (float) $pendingEntry['reference_price'],
                'entry_futures_price' => $futuresPrice,
                'entry_trigger_price' => (float) $pendingEntry['entry_trigger_price'],
                'option_type' => $pendingEntry['option_type'],
                'option_symbol' => (string) $option['symbol'],
                'option_token' => (string) $option['token'],
                'expiry' => $options['expiry']->format('Y-m-d'),
                'strike' => $options['strike'],
                'option_price' => $optionPrice,
                'signal_time' => $signalTime,
                'entry_time' => $tickTime,
                'paper_trade_id' => $trade->id,
            ]);
        });

        Cache::forget(self::PENDING_ENTRY_CACHE_KEY);
        Cache::forget(self::ENTRY_PRICE_ATTEMPT_CACHE_KEY);
        Cache::forget(self::OPEN_TRADE_CACHE_KEY);
        $this->openTradeState();

        if (! $alert->wasRecentlyCreated) {
            return ['status' => 'duplicate_signal'];
        }

        return [
            'status' => 'signal',
            'direction' => $pendingEntry['direction'],
            'option_type' => $pendingEntry['option_type'],
            'option_symbol' => $option['symbol'],
            'option_price' => $optionPrice,
            'entry_futures_price' => $futuresPrice,
            'target_futures_price' => $targetPrice,
            'alert_id' => $alert->id,
        ];
    }

    /**
     * @return array{future: array<string, mixed>, instruments: list<array<string, mixed>>}
     */
    public function streamContract(CarbonImmutable $now): array
    {
        $instruments = $this->angelOne->instruments();

        return [
            'future' => $this->nearestInstrument($instruments, 'FUTIDX', $now),
            'instruments' => $instruments,
        ];
    }

    /**
     * @return list<array<int, string|int|float|null>>
     */
    public function closedMarketCandles(string $token, CarbonImmutable $now): array
    {
        $historyStart = $now->subDays(7)->setTime(9, 15);
        $candles = $this->angelOne->candles(
            $token,
            $historyStart->format('Y-m-d H:i'),
            $now->format('Y-m-d H:i'),
        );
        $closedCandles = [];

        foreach ($candles as $candle) {
            if (! isset($candle[0])) {
                continue;
            }

            $candleTime = CarbonImmutable::parse((string) $candle[0], config('angelone.timezone'));
            $candleEnd = $candleTime->addMinutes(FiveMinuteCandleAggregator::INTERVAL_MINUTES);
            $sessionOpen = $candleTime->setTime(9, 15);
            $sessionClose = $candleTime->setTime(15, 30);

            if ($candleTime->lessThan($historyStart)
                || $candleTime->lessThan($sessionOpen)
                || $candleEnd->greaterThan($sessionClose)
                || $candleEnd->greaterThan($now)) {
                continue;
            }

            $closedCandles[$candleTime->getTimestamp()] = $candle;
        }

        ksort($closedCandles, SORT_NUMERIC);

        return array_values($closedCandles);
    }

    /**
     * @param  list<array<int, string|int|float|null>>  $cachedCandles
     * @param  list<array<int, string|int|float|null>>  $historicalCandles
     * @return list<array<int, string|int|float|null>>
     */
    public function mergeClosedCandles(array $cachedCandles, array $historicalCandles): array
    {
        $candlesByTime = [];

        foreach (array_merge($cachedCandles, $historicalCandles) as $candle) {
            if (! isset($candle[0])) {
                continue;
            }

            $timestamp = CarbonImmutable::parse((string) $candle[0], config('angelone.timezone'))->getTimestamp();
            $candlesByTime[$timestamp] = $candle;
        }

        ksort($candlesByTime, SORT_NUMERIC);

        return array_slice(array_values($candlesByTime), -500);
    }

    /**
     * @param  list<array<string, mixed>>  $instruments
     * @return array{strike: int, expiry: CarbonImmutable, ce: array<string, mixed>, pe: array<string, mixed>}
     */
    public function atmOptions(array $instruments, float $futuresPrice, CarbonImmutable $now): array
    {
        $expiry = $this->nearestMonthlyExpiry($instruments, $now);
        $strike = $this->atmStrike($futuresPrice);

        return [
            'strike' => $strike,
            'expiry' => $expiry,
            'ce' => $this->findOption($instruments, $expiry, $strike, 'CE'),
            'pe' => $this->findOption($instruments, $expiry, $strike, 'PE'),
        ];
    }

    public function atmStrike(float $futuresPrice): int
    {
        return (int) (round($futuresPrice / 50) * 50);
    }

    /**
     * @param  list<array<string, mixed>>  $instruments
     * @return array<string, mixed>
     */
    private function nearestInstrument(array $instruments, string $instrumentType, CarbonImmutable $now): array
    {
        $eligible = array_values(array_filter($instruments, function (array $instrument) use ($instrumentType, $now): bool {
            return ($instrument['name'] ?? null) === 'NIFTY'
                && ($instrument['exch_seg'] ?? null) === 'NFO'
                && ($instrument['instrumenttype'] ?? null) === $instrumentType
                && $this->expiryDate((string) ($instrument['expiry'] ?? ''))?->greaterThanOrEqualTo($now->startOfDay());
        }));

        usort($eligible, fn (array $left, array $right): int => $this->expiryDate((string) $left['expiry'])->getTimestamp() <=> $this->expiryDate((string) $right['expiry'])->getTimestamp());

        if (! isset($eligible[0]['token'], $eligible[0]['symbol'])) {
            throw new RuntimeException("No current NIFTY {$instrumentType} contract was found.");
        }

        return $eligible[0];
    }

    /**
     * @param  list<array<string, mixed>>  $instruments
     */
    private function nearestMonthlyExpiry(array $instruments, CarbonImmutable $now): CarbonImmutable
    {
        $expiriesByMonth = [];

        foreach ($instruments as $instrument) {
            if (($instrument['name'] ?? null) !== 'NIFTY'
                || ($instrument['exch_seg'] ?? null) !== 'NFO'
                || ($instrument['instrumenttype'] ?? null) !== 'OPTIDX') {
                continue;
            }

            $expiry = $this->expiryDate((string) ($instrument['expiry'] ?? ''));

            if ($expiry === null || $expiry->lessThan($now->startOfDay())) {
                continue;
            }

            $month = $expiry->format('Y-m');

            if (! isset($expiriesByMonth[$month]) || $expiry->greaterThan($expiriesByMonth[$month])) {
                $expiriesByMonth[$month] = $expiry;
            }
        }

        if ($expiriesByMonth === []) {
            throw new RuntimeException('No current NIFTY monthly option expiry was found.');
        }

        ksort($expiriesByMonth);

        return array_values($expiriesByMonth)[0];
    }

    /**
     * @param  list<array<string, mixed>>  $instruments
     * @return array<string, mixed>
     */
    private function findOption(array $instruments, CarbonImmutable $expiry, int $strike, string $optionType): array
    {
        foreach ($instruments as $instrument) {
            $instrumentStrike = isset($instrument['strike']) ? (int) round(((float) $instrument['strike']) / 100) : null;

            if (($instrument['name'] ?? null) === 'NIFTY'
                && ($instrument['exch_seg'] ?? null) === 'NFO'
                && ($instrument['instrumenttype'] ?? null) === 'OPTIDX'
                && ($instrument['symbol'] ?? null) !== null
                && str_ends_with((string) $instrument['symbol'], $optionType)
                && $instrumentStrike === $strike
                && $this->expiryDate((string) ($instrument['expiry'] ?? ''))?->isSameDay($expiry)) {
                return $instrument;
            }
        }

        throw new RuntimeException("The monthly-expiry NIFTY {$strike} {$optionType} contract was not found.");
    }

    /**
     * @return array{id: int, direction: string, target_price: float}|null
     */
    private function openTradeState(): ?array
    {
        $cachedTrade = Cache::get(self::OPEN_TRADE_CACHE_KEY);

        if (is_array($cachedTrade)) {
            return $cachedTrade === [] ? null : $cachedTrade;
        }

        $trade = PaperTrade::query()->where('status', 'open')->latest('signal_time')->first();

        if ($trade === null) {
            Cache::put(self::OPEN_TRADE_CACHE_KEY, [], now()->addDay());

            return null;
        }

        $targetPrice = $trade->futures_target_price;

        if ($targetPrice === null) {
            $alert = PaperAlert::query()->where('paper_trade_id', $trade->id)->first();
            $entryFuturesPrice = (float) ($alert?->entry_futures_price ?? $alert?->futures_price ?? 0);

            if ($entryFuturesPrice > 0) {
                $offset = (float) config('angelone.reference_offset_percent') / 100;
                $targetPrice = round($entryFuturesPrice * ($trade->direction === 'CE' ? 1 - $offset : 1 + $offset), 2);
                $trade->update([
                    'entry_futures_price' => $entryFuturesPrice,
                    'futures_target_price' => $targetPrice,
                ]);
            }
        }

        if ($targetPrice === null) {
            throw new RuntimeException("Open paper trade {$trade->id} has no NIFTY futures exit target.");
        }

        $tradeState = [
            'id' => $trade->id,
            'direction' => $trade->direction,
            'target_price' => (float) $targetPrice,
        ];
        Cache::put(self::OPEN_TRADE_CACHE_KEY, $tradeState, now()->addDay());

        return $tradeState;
    }

    private function expiryDate(string $expiry): ?CarbonImmutable
    {
        if ($expiry === '') {
            return null;
        }

        foreach (['dMY', 'Y-m-d'] as $format) {
            $date = CarbonImmutable::createFromFormat('!'.$format, strtoupper($expiry), config('angelone.timezone'));

            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }

    private function recordSupertrendFlip(
        string $direction,
        string $optionType,
        CarbonImmutable $signalTime,
        float $futuresClose,
        float $supertrendPrice,
        float $referencePrice,
        CarbonImmutable $detectedAt,
    ): void {
        $eventId = hash('sha256', $direction.'|'.$signalTime->toIso8601String());
        $events = Cache::get(self::FLIP_EVENTS_CACHE_KEY, []);
        $events = is_array($events) ? $events : [];

        foreach ($events as $event) {
            if (is_array($event) && ($event['id'] ?? null) === $eventId) {
                return;
            }
        }

        $events[] = [
            'id' => $eventId,
            'direction' => $direction,
            'option_type' => $optionType,
            'signal_time' => $signalTime->toIso8601String(),
            'detected_at' => $detectedAt->toIso8601String(),
            'futures_close' => $futuresClose,
            'supertrend_price' => $supertrendPrice,
            'reference_price' => $referencePrice,
        ];

        Cache::put(
            self::FLIP_EVENTS_CACHE_KEY,
            array_slice($events, -self::FLIP_EVENTS_LIMIT),
            now()->addDay(),
        );
    }
}

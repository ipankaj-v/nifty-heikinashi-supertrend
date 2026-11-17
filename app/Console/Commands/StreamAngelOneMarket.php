<?php

namespace App\Console\Commands;

use App\Services\AngelOneClient;
use App\Services\FiveMinuteCandleAggregator;
use App\Services\PaperTradingScanner;
use App\Services\SmartApiLtpPacketDecoder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use WebSocket\Client as WebSocketClient;
use WebSocket\Configuration as WebSocketConfiguration;
use WebSocket\Connection;
use WebSocket\Message\Binary;
use WebSocket\Message\Text;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingInterval;
use WebSocket\Middleware\PingResponder;

#[Signature('angelone:stream')]
#[Description('Stream NIFTY futures ticks and process five-minute paper-trading signals')]
class StreamAngelOneMarket extends Command
{
    private const LOCK_KEY = 'angelone.stream-worker';

    private const STATUS_KEY = 'angelone.stream.status';

    public function handle(
        AngelOneClient $angelOne,
        PaperTradingScanner $scanner,
        SmartApiLtpPacketDecoder $decoder,
    ): int {
        if (! $angelOne->isConfigured()) {
            $this->error('Complete the Angel One settings in the server .env file first.');

            return self::FAILURE;
        }

        $lock = Cache::lock(self::LOCK_KEY, 120);

        if (! $lock->get()) {
            $this->error('An Angel One WebSocket worker is already running.');

            return self::FAILURE;
        }

        try {
            $now = CarbonImmutable::now(config('angelone.timezone'));
            $sessionDate = $now->toDateString();
            $historyBackfillStartedAt = $now->startOfMinute()->subMinutes($now->minute % FiveMinuteCandleAggregator::INTERVAL_MINUTES);
            $contract = $scanner->streamContract($now);
            $future = $contract['future'];
            $instruments = $contract['instruments'];
            $cacheKey = 'angelone.stream.candles.'.hash('sha256', $now->toDateString().'|'.$future['token']);
            $candles = Cache::get($cacheKey, []);
            $candles = is_array($candles) ? $candles : [];
            $historyError = null;
            $historyRetryAt = null;
            $historyBackfillPending = false;
            $pendingEntry = Cache::get('angelone.pending_entry');

            if (is_array($pendingEntry)
                && ! CarbonImmutable::parse($pendingEntry['signal_time'], config('angelone.timezone'))->isSameDay($now)) {
                Cache::forget('angelone.pending_entry');
            }

            try {
                $historicalCandles = array_slice(
                    $scanner->closedMarketCandles((string) $future['token'], $now),
                    -500,
                );

                if ($historicalCandles !== []) {
                    $candles = $historicalCandles;
                    Cache::put($cacheKey, $candles, now()->addHours(12));
                    $this->info('Loaded '.count($candles).' closed candles from recent sessions for indicator history.');
                } else {
                    $historyError = 'Angel One returned no closed market candles for indicator history.';
                    $historyRetryAt = $now->addMinutes(15);
                    $historyBackfillPending = true;
                    Log::warning($historyError, [
                        'cached_candle_count' => count($candles),
                        'retry_at' => $historyRetryAt->toIso8601String(),
                    ]);

                    if ($candles === []) {
                        Cache::forget($cacheKey);
                        $this->warn('No historical closed candles were returned; warming up from live candles.');
                    } else {
                        $this->warn('No historical candles were returned; using '.count($candles).' cached closed candles.');
                    }
                }
            } catch (Throwable $exception) {
                $historyError = $exception->getMessage();
                $retryAt = $angelOne->rateLimitRetryAt();
                $historyRetryAt = $retryAt !== null
                    ? CarbonImmutable::parse($retryAt, config('angelone.timezone'))
                    : $now->addMinutes(15);
                $historyBackfillPending = true;
                $fallbackSource = $candles === [] ? 'live candles' : 'cached closed candles';
                Log::warning('Angel One startup candle backfill failed; using '.$fallbackSource.'.', [
                    'error' => $historyError,
                    'cached_candle_count' => count($candles),
                    'retry_at' => $historyRetryAt?->toIso8601String(),
                ]);
                $this->warn('Historical candle backfill failed; using '.$fallbackSource.': '.$historyError);
            }

            if ($historyRetryAt !== null) {
                $this->info('Historical candle backfill will retry automatically after '.$historyRetryAt->format('g:i:s a').'.');
            }

            Cache::forget('angelone.latest_snapshot');
            Cache::forget('angelone.last_scan_at');
            Cache::forget('angelone.open_trade_state');

            if ($candles !== []) {
                $scanner->processClosedCandles($candles, $future, $now, false);
            }

            $aggregator = new FiveMinuteCandleAggregator;
            $lastStatusUpdate = 0;
            $lastHeartbeatUpdate = 0;
            $lastLockRefresh = 0;
            $retryDelay = 5;
            $sessionChanged = false;
            $atmOptions = null;
            $subscribedOptionTokens = [];
            $optionPrices = [];
            $activeAtmStrike = null;
            $candidateAtmStrike = null;
            $candidateAtmSince = 0;
            $lastAtmLookupAt = 0;
            $lastFuturePrice = null;
            $lastFutureTickTime = null;

            $this->info("Streaming {$future['symbol']} ({$future['token']}); paper-only, no orders will be sent.");
            $this->info('Historical candles seed the indicator; paper signals are evaluated only after a five-minute candle closes.');
            $this->info('ATM CE/PE quotes follow the live futures price.');

            Cache::put(self::STATUS_KEY, [
                'state' => 'connecting',
                'future_symbol' => $future['symbol'],
                'candle_count' => count($candles),
                'ready' => false,
                'heartbeat_at' => now()->toIso8601String(),
                'live_price' => null,
                'last_tick_at' => null,
                'atm_strike' => null,
                'atm_expiry' => null,
                'atm_ce_symbol' => null,
                'atm_ce_price' => null,
                'atm_ce_time' => null,
                'atm_pe_symbol' => null,
                'atm_pe_price' => null,
                'atm_pe_time' => null,
                'atm_error' => null,
                'pending_entry' => Cache::get('angelone.pending_entry'),
                'last_error' => $historyError,
                'history_retry_at' => $historyRetryAt?->toIso8601String(),
            ], now()->addMinutes(2));

            $credentials = $angelOne->websocketCredentials();

            while (true) {
                $atmOptions = null;
                $subscribedOptionTokens = [];
                $optionPrices = [];
                $activeAtmStrike = null;
                $candidateAtmStrike = null;
                $candidateAtmSince = 0;
                $lastAtmLookupAt = 0;
                $lastStatusUpdate = 0;

                try {
                    $client = new WebSocketClient(
                        'wss://smartapisocket.angelone.in/smart-stream',
                        new WebSocketConfiguration(timeout: 90),
                    );
                    $client
                        ->addHeader('Authorization', $credentials['jwt_token'])
                        ->addHeader('x-api-key', (string) config('angelone.api_key'))
                        ->addHeader('x-client-code', (string) config('angelone.client_code'))
                        ->addHeader('x-feed-token', $credentials['feed_token'])
                        ->addMiddleware(new CloseHandler)
                        ->addMiddleware(new PingResponder)
                        ->addMiddleware(new PingInterval(30))
                        ->onTick(function (WebSocketClient $client) use ($lock, &$lastHeartbeatUpdate, &$lastLockRefresh): void {
                            if (time() - $lastLockRefresh >= 30) {
                                if (! $lock->refresh(120)) {
                                    throw new RuntimeException('The WebSocket worker lock could not be refreshed.');
                                }

                                $lastLockRefresh = time();
                            }

                            if (time() - $lastHeartbeatUpdate >= 15) {
                                $this->updateStatus(['heartbeat_at' => now()->toIso8601String()]);
                                $lastHeartbeatUpdate = time();
                            }
                        })
                        ->onText(function (WebSocketClient $client, Connection $connection, Text $message): void {
                            $response = json_decode($message->getContent(), true);

                            if (is_array($response)
                                && (($response['status'] ?? true) === false || isset($response['errorcode']))) {
                                $error = (string) ($response['message'] ?? 'Angel One rejected the WebSocket subscription.');
                                Log::error('Angel One WebSocket returned an error.', ['message' => $error]);
                                $this->updateStatus([
                                    'state' => 'error',
                                    'last_error' => $error,
                                ]);
                            }
                        })
                        ->onBinary(function (WebSocketClient $client, Connection $connection, Binary $message) use (
                            $decoder,
                            $future,
                            $aggregator,
                            &$candles,
                            &$lastStatusUpdate,
                            &$sessionChanged,
                            &$atmOptions,
                            &$subscribedOptionTokens,
                            &$optionPrices,
                            &$activeAtmStrike,
                            &$candidateAtmStrike,
                            &$candidateAtmSince,
                            &$lastAtmLookupAt,
                            &$lastFuturePrice,
                            &$lastFutureTickTime,
                            &$historyError,
                            &$historyRetryAt,
                            &$historyBackfillPending,
                            $sessionDate,
                            $historyBackfillStartedAt,
                            $cacheKey,
                            $instruments,
                            $scanner,
                            $angelOne,
                        ): void {
                            $tick = $decoder->decode($message->getContent());

                            if ($tick === null) {
                                return;
                            }

                            $isFutureTick = $tick['token'] === (string) $future['token'];

                            if (! $isFutureTick && ! in_array($tick['token'], $subscribedOptionTokens, true)) {
                                return;
                            }

                            $tickTime = $tick['time']->setTimezone(config('angelone.timezone'));

                            if ($tickTime->toDateString() !== $sessionDate) {
                                $sessionChanged = true;
                                $this->updateStatus([
                                    'state' => 'stopped',
                                    'heartbeat_at' => now()->toIso8601String(),
                                    'last_error' => 'Market date changed; restart the worker for the new session.',
                                ]);
                                $client->close(1000, 'Market date changed');

                                return;
                            }

                            if ($tickTime->isWeekend()
                                || $tickTime->format('H:i') < '09:15'
                                || $tickTime->format('H:i') > '15:30') {
                                return;
                            }

                            if ($isFutureTick
                                && $historyBackfillPending
                                && $historyRetryAt !== null
                                && $tickTime->greaterThanOrEqualTo($historyRetryAt)
                                && ! $angelOne->isRateLimited()) {
                                try {
                                    $historicalCandles = $scanner->closedMarketCandles((string) $future['token'], $tickTime);

                                    if ($historicalCandles === []) {
                                        throw new RuntimeException('Angel One returned no closed candles when retrying historical backfill.');
                                    }

                                    $candles = $scanner->mergeClosedCandles($candles, $historicalCandles);
                                    Cache::put($cacheKey, $candles, now()->addHours(12));
                                    Cache::forget('angelone.pending_entry');
                                    $result = $scanner->processClosedCandlesSince(
                                        $candles,
                                        $future,
                                        $tickTime,
                                        $historyBackfillStartedAt,
                                    );

                                    $historyError = null;
                                    $historyRetryAt = null;
                                    $historyBackfillPending = false;
                                    $ready = $result['status'] !== 'warming_up';

                                    Log::info('Angel One historical candle backfill succeeded after cooldown.', [
                                        'candle_count' => count($candles),
                                        'latest_candle_time' => $candles[array_key_last($candles)][0],
                                    ]);
                                    $this->updateStatus([
                                        'state' => $ready ? 'connected' : 'warming_up',
                                        'candle_count' => count($candles),
                                        'ready' => $ready,
                                        'last_error' => null,
                                        'history_retry_at' => null,
                                        'pending_entry' => Cache::get('angelone.pending_entry'),
                                    ]);
                                } catch (Throwable $exception) {
                                    $historyError = $exception->getMessage();
                                    $retryAt = $angelOne->rateLimitRetryAt();
                                    $historyRetryAt = $retryAt !== null
                                        ? CarbonImmutable::parse($retryAt, config('angelone.timezone'))
                                        : $tickTime->addMinutes(15);
                                    $historyBackfillPending = true;

                                    Log::warning('Angel One deferred historical candle backfill failed.', [
                                        'error' => $historyError,
                                        'retry_at' => $historyRetryAt?->toIso8601String(),
                                    ]);
                                    $this->updateStatus([
                                        'last_error' => $historyError,
                                        'history_retry_at' => $historyRetryAt?->toIso8601String(),
                                    ]);
                                }
                            }

                            if ($isFutureTick) {
                                $lastFuturePrice = $tick['price'];
                                $lastFutureTickTime = $tickTime;
                                $desiredAtmStrike = $scanner->atmStrike($tick['price']);

                                if ($desiredAtmStrike === $activeAtmStrike) {
                                    $candidateAtmStrike = null;
                                } elseif ($desiredAtmStrike !== $candidateAtmStrike) {
                                    $candidateAtmStrike = $desiredAtmStrike;
                                    $candidateAtmSince = time();
                                } elseif (time() - $candidateAtmSince >= 2 && time() - $lastAtmLookupAt >= 5) {
                                    $lastAtmLookupAt = time();

                                    try {
                                        $newAtmOptions = $scanner->atmOptions($instruments, $tick['price'], $tickTime);
                                        $newOptionTokens = [
                                            (string) $newAtmOptions['ce']['token'],
                                            (string) $newAtmOptions['pe']['token'],
                                        ];

                                        if ($subscribedOptionTokens !== $newOptionTokens) {
                                            if ($subscribedOptionTokens !== []) {
                                                $client->text($this->subscriptionMessage(0, $subscribedOptionTokens));
                                            }

                                            $client->text($this->subscriptionMessage(1, $newOptionTokens));
                                            $subscribedOptionTokens = $newOptionTokens;
                                            $optionPrices = [];
                                        }

                                        $atmOptions = $newAtmOptions;
                                        $activeAtmStrike = $newAtmOptions['strike'];
                                        $candidateAtmStrike = null;
                                        $lastStatusUpdate = time();
                                        $this->updateStatus([
                                            'atm_strike' => $newAtmOptions['strike'],
                                            'atm_expiry' => $newAtmOptions['expiry']->format('Y-m-d'),
                                            'atm_ce_symbol' => $newAtmOptions['ce']['symbol'],
                                            'atm_ce_price' => null,
                                            'atm_ce_time' => null,
                                            'atm_pe_symbol' => $newAtmOptions['pe']['symbol'],
                                            'atm_pe_price' => null,
                                            'atm_pe_time' => null,
                                            'atm_error' => null,
                                        ]);
                                    } catch (RuntimeException $exception) {
                                        Log::warning('Unable to select current ATM NIFTY option contracts.', [
                                            'strike' => $desiredAtmStrike,
                                            'error' => $exception->getMessage(),
                                        ]);
                                        $this->updateStatus([
                                            'atm_error' => $exception->getMessage(),
                                            'atm_target_strike' => $desiredAtmStrike,
                                        ]);
                                    }
                                }

                                $closedCandle = $aggregator->addTick($tickTime, $tick['price']);
                            } else {
                                $optionPrices[$tick['token']] = [
                                    'price' => $tick['price'],
                                    'time' => $tickTime->toIso8601String(),
                                ];
                                $closedCandle = null;
                            }

                            $candleCount = count($candles);
                            $minimumCandles = (int) config('angelone.atr_period') + 2;
                            if (time() - $lastStatusUpdate >= 5) {
                                $marketStatus = [
                                    'state' => $candleCount >= $minimumCandles ? 'connected' : 'warming_up',
                                    'heartbeat_at' => now()->toIso8601String(),
                                    'candle_count' => $candleCount,
                                    'ready' => $candleCount >= $minimumCandles,
                                    'pending_entry' => Cache::get('angelone.pending_entry'),
                                ];

                                if ($lastFuturePrice !== null && $lastFutureTickTime !== null) {
                                    $marketStatus['live_price'] = $lastFuturePrice;
                                    $marketStatus['last_tick_at'] = $lastFutureTickTime->toIso8601String();
                                }

                                if ($atmOptions !== null) {
                                    $ceQuote = $optionPrices[(string) $atmOptions['ce']['token']] ?? null;
                                    $peQuote = $optionPrices[(string) $atmOptions['pe']['token']] ?? null;
                                    $marketStatus['atm_ce_price'] = $ceQuote['price'] ?? null;
                                    $marketStatus['atm_ce_time'] = $ceQuote['time'] ?? null;
                                    $marketStatus['atm_pe_price'] = $peQuote['price'] ?? null;
                                    $marketStatus['atm_pe_time'] = $peQuote['time'] ?? null;
                                }

                                $this->updateStatus($marketStatus);
                                $lastStatusUpdate = time();
                            }

                            if ($closedCandle === null) {
                                if ($isFutureTick) {
                                    $this->processFuturePriceTick($scanner, $tick['price'], $tickTime, $future, $instruments);
                                }

                                return;
                            }

                            $historyWasBackfilled = false;
                            $historyError = null;

                            if ($candles !== []) {
                                $lastCandleEnd = CarbonImmutable::parse(
                                    (string) $candles[array_key_last($candles)][0],
                                    config('angelone.timezone'),
                                )->addMinutes(FiveMinuteCandleAggregator::INTERVAL_MINUTES);
                                $closedCandleTime = CarbonImmutable::parse(
                                    (string) $closedCandle[0],
                                    config('angelone.timezone'),
                                );

                                if ($closedCandleTime->lessThan($lastCandleEnd)) {
                                    return;
                                }

                                if ($lastCandleEnd->lessThan($closedCandleTime)) {
                                    Log::warning('Angel One WebSocket candle gap detected; backfilling recent market history.', [
                                        'last_candle_time' => $candles[array_key_last($candles)][0],
                                        'next_candle_time' => $closedCandle[0],
                                    ]);

                                    try {
                                        $candles = $scanner->closedMarketCandles((string) $future['token'], $tickTime);

                                        if ($candles === []) {
                                            throw new RuntimeException('Angel One returned no closed market candles to backfill.');
                                        }

                                        $lastBackfilledCandleTime = CarbonImmutable::parse(
                                            (string) $candles[array_key_last($candles)][0],
                                            config('angelone.timezone'),
                                        );

                                        if ($lastBackfilledCandleTime->lessThan($closedCandleTime)) {
                                            $candles[] = $closedCandle;
                                        }

                                        $candles = array_slice($candles, -500);
                                        $historyWasBackfilled = true;
                                        Log::info('Angel One market candle backfill completed.', [
                                            'candle_count' => count($candles),
                                            'latest_candle_time' => $candles[array_key_last($candles)][0],
                                        ]);
                                    } catch (Throwable $exception) {
                                        $historyError = $exception->getMessage();
                                        $candles = [];
                                        Cache::forget('angelone.latest_snapshot');
                                        Cache::forget('angelone.last_scan_at');
                                        Log::error('Angel One market candle backfill failed; starting a fresh live warm-up.', [
                                            'error' => $historyError,
                                        ]);
                                        $this->warn('Candle history backfill failed; warming up from live candles: '.$historyError);
                                    }
                                }
                            }

                            if (! $historyWasBackfilled) {
                                $candles[] = $closedCandle;
                                $candles = array_slice($candles, -500);
                            }

                            Cache::put($cacheKey, $candles, now()->addHours(12));

                            try {
                                $result = $scanner->processClosedCandles(
                                    $candles,
                                    $future,
                                    $tickTime,
                                );
                            } catch (Throwable $exception) {
                                Log::error('Angel One WebSocket candle processing failed.', [
                                    'error' => $exception->getMessage(),
                                    'candle_time' => $closedCandle[0],
                                ]);
                                $this->updateStatus([
                                    'state' => 'error',
                                    'last_error' => $exception->getMessage(),
                                    'candle_count' => count($candles),
                                ]);

                                return;
                            }

                            $ready = $result['status'] !== 'warming_up';
                            $this->updateStatus([
                                'state' => $ready ? 'connected' : 'warming_up',
                                'candle_count' => count($candles),
                                'ready' => $ready,
                                'last_error' => $historyError,
                            ]);

                            if ($result['status'] === 'pending_entry') {
                                Log::info('Angel One Supertrend flip is waiting for its futures entry threshold.', [
                                    'direction' => $result['direction'],
                                    'entry_trigger_price' => $result['entry_trigger_price'],
                                ]);
                                $this->updateStatus([
                                    'pending_entry' => Cache::get('angelone.pending_entry'),
                                ]);
                            }

                            $this->processFuturePriceTick($scanner, $tick['price'], $tickTime, $future, $instruments);
                        });

                    $client->connect();
                    $client->text($this->subscriptionMessage(1, [(string) $future['token']]));
                    $this->info('WebSocket connected and NIFTY futures subscription sent.');
                    $this->updateStatus([
                        'state' => count($candles) >= (int) config('angelone.atr_period') + 2 ? 'connected' : 'warming_up',
                        'heartbeat_at' => now()->toIso8601String(),
                        'pending_entry' => Cache::get('angelone.pending_entry'),
                    ]);
                    $client->start();

                    if ($sessionChanged) {
                        $this->info('Market date changed. Restart the stream worker for the new session.');

                        return self::SUCCESS;
                    }

                    throw new RuntimeException('Angel One WebSocket connection closed unexpectedly.');
                } catch (Throwable $exception) {
                    if ($sessionChanged) {
                        $this->info('Market date changed. Restart the stream worker for the new session.');

                        return self::SUCCESS;
                    }

                    Log::warning('Angel One WebSocket disconnected; reconnecting.', [
                        'error' => $exception->getMessage(),
                        'retry_seconds' => $retryDelay,
                    ]);
                    $this->warn("WebSocket disconnected; retrying in {$retryDelay} seconds.");
                    $this->updateStatus([
                        'state' => 'reconnecting',
                        'heartbeat_at' => now()->toIso8601String(),
                        'last_error' => $exception->getMessage(),
                    ]);

                    if (! $lock->refresh(120)) {
                        throw new RuntimeException('The WebSocket worker lock could not be refreshed.', previous: $exception);
                    }

                    sleep($retryDelay);
                    $retryDelay = min(60, $retryDelay * 2);
                    $credentials = $angelOne->websocketCredentials();
                }
            }
        } catch (Throwable $exception) {
            $this->updateStatus([
                'state' => 'error',
                'heartbeat_at' => now()->toIso8601String(),
                'last_error' => $exception->getMessage(),
            ]);
            Log::error('Angel One WebSocket worker stopped.', ['error' => $exception->getMessage()]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function updateStatus(array $updates): void
    {
        Cache::put(
            self::STATUS_KEY,
            array_merge(Cache::get(self::STATUS_KEY, []), $updates),
            now()->addMinutes(2),
        );
    }

    /**
     * @param  array<string, mixed>  $future
     * @param  list<array<string, mixed>>  $instruments
     */
    private function processFuturePriceTick(
        PaperTradingScanner $scanner,
        float $futuresPrice,
        CarbonImmutable $tickTime,
        array $future,
        array $instruments,
    ): void {
        try {
            $result = $scanner->processFutureTick($futuresPrice, $tickTime, $future, $instruments);

            if ($result['status'] === 'signal') {
                Log::info('Angel One paper-trading entry triggered.', [
                    'direction' => $result['direction'],
                    'option_symbol' => $result['option_symbol'],
                    'option_price' => $result['option_price'],
                    'futures_entry_price' => $result['entry_futures_price'],
                    'futures_target_price' => $result['target_futures_price'],
                ]);
                $this->updateStatus(['pending_entry' => null, 'last_error' => null]);
            } elseif ($result['status'] === 'exit') {
                Log::info('Angel One paper-trading profit target reached.', [
                    'option_symbol' => $result['option_symbol'],
                    'option_exit_price' => $result['option_exit_price'],
                    'futures_exit_price' => $result['futures_exit_price'],
                ]);
                $this->updateStatus(['pending_entry' => null, 'last_error' => null]);
            } elseif ($result['status'] === 'rate_limited') {
                $this->updateStatus([
                    'last_error' => 'Angel One rate limit prevents paper entry/exit price retrieval until '.$result['retry_at'].'.',
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Angel One paper-trading price trigger failed.', [
                'error' => $exception->getMessage(),
                'futures_price' => $futuresPrice,
            ]);
            $this->updateStatus(['last_error' => $exception->getMessage()]);
        }
    }

    /**
     * @param  list<string>  $tokens
     */
    private function subscriptionMessage(int $action, array $tokens): string
    {
        return json_encode([
            'correlationID' => 'niftydesk',
            'action' => $action,
            'params' => [
                'mode' => 1,
                'tokenList' => [[
                    'exchangeType' => 2,
                    'tokens' => $tokens,
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}

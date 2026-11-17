<?php

namespace Tests\Feature\Services;

use App\Services\AngelOneClient;
use App\Services\HeikinAshiSupertrend;
use App\Services\PaperTradingScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PaperTradingScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fetches_closed_candles_across_recent_sessions_in_time_order(): void
    {
        $angelOne = $this->createMock(AngelOneClient::class);
        $angelOne->expects($this->once())
            ->method('candles')
            ->with('61471', '2026-11-10 09:15', '2026-11-17 12:05')
            ->willReturn([
                ['2026-11-17T12:05:00+05:30', 102, 103, 101, 102, 10],
                ['2026-11-17T12:00:00+05:30', 101, 102, 100, 101, 12],
                ['2026-11-16T15:30:00+05:30', 103, 104, 102, 103, 8],
                ['2026-11-16T15:25:00+05:30', 102, 104, 101, 103, 9],
                ['2026-11-16T09:10:00+05:30', 99, 100, 98, 99, 5],
                ['2026-11-16T09:15:00+05:30', 100, 101, 99, 100, 20],
            ]);
        $scanner = new PaperTradingScanner($angelOne, new HeikinAshiSupertrend);

        $candles = $scanner->closedMarketCandles(
            '61471',
            CarbonImmutable::parse('2026-11-17 12:05:15', 'Asia/Kolkata'),
        );

        $this->assertSame([
            ['2026-11-16T09:15:00+05:30', 100, 101, 99, 100, 20],
            ['2026-11-16T15:25:00+05:30', 102, 104, 101, 103, 9],
            ['2026-11-17T12:00:00+05:30', 101, 102, 100, 101, 12],
        ], $candles);
    }

    public function test_it_merges_cached_and_historical_candles_without_duplicates(): void
    {
        $scanner = new PaperTradingScanner(new AngelOneClient, new HeikinAshiSupertrend);

        $candles = $scanner->mergeClosedCandles(
            [
                ['2026-11-17T09:15:00+05:30', 100, 102, 99, 101, 10],
                ['2026-11-17T09:20:00+05:30', 101, 103, 100, 102, 12],
            ],
            [
                ['2026-11-17T09:15:00+05:30', 100, 101, 99, 100, 8],
                ['2026-11-17T09:25:00+05:30', 102, 104, 101, 103, 14],
            ],
        );

        $this->assertSame([
            ['2026-11-17T09:15:00+05:30', 100, 101, 99, 100, 8],
            ['2026-11-17T09:20:00+05:30', 101, 103, 100, 102, 12],
            ['2026-11-17T09:25:00+05:30', 102, 104, 101, 103, 14],
        ], $candles);
    }

    public function test_it_replays_candles_closed_while_history_backfill_was_pending(): void
    {
        config([
            'cache.default' => 'array',
            'angelone.atr_period' => 2,
            'angelone.supertrend_multiplier' => 0.2,
            'angelone.reference_offset_percent' => 0.10,
        ]);
        Cache::flush();

        $scanner = new PaperTradingScanner(new AngelOneClient, new HeikinAshiSupertrend);
        $candles = [
            ['2026-11-17T09:15:00+05:30', 100, 101, 99, 100],
            ['2026-11-17T09:20:00+05:30', 100, 101, 99, 100],
            ['2026-11-17T09:25:00+05:30', 100, 100.5, 99.5, 100.2],
            ['2026-11-17T09:30:00+05:30', 100.2, 102, 100, 102],
        ];

        $result = $scanner->processClosedCandlesSince(
            $candles,
            ['symbol' => 'NIFTY23NOV26FUT', 'token' => '61471'],
            CarbonImmutable::parse('2026-11-17 09:35:00', 'Asia/Kolkata'),
            CarbonImmutable::parse('2026-11-17 09:25:00', 'Asia/Kolkata'),
        );

        $this->assertSame('pending_entry', $result['status']);
        $this->assertSame('bullish', $result['direction']);
        $this->assertSame('PE', $result['option_type']);
        $this->assertSame('bullish', Cache::get('angelone.flip_events')[0]['direction']);
    }

    public function test_it_selects_both_options_at_the_nearest_strike_and_monthly_expiry(): void
    {
        $instruments = [
            [
                'name' => 'NIFTY',
                'exch_seg' => 'NFO',
                'instrumenttype' => 'OPTIDX',
                'symbol' => 'NIFTY19NOV2626100CE',
                'token' => '99',
                'expiry' => '19NOV2026',
                'strike' => '2610000.000000',
            ],
            [
                'name' => 'NIFTY',
                'exch_seg' => 'NFO',
                'instrumenttype' => 'OPTIDX',
                'symbol' => 'NIFTY19NOV2626100PE',
                'token' => '100',
                'expiry' => '19NOV2026',
                'strike' => '2610000.000000',
            ],
            [
                'name' => 'NIFTY',
                'exch_seg' => 'NFO',
                'instrumenttype' => 'OPTIDX',
                'symbol' => 'NIFTY26NOV2626100CE',
                'token' => '101',
                'expiry' => '26NOV2026',
                'strike' => '2610000.000000',
            ],
            [
                'name' => 'NIFTY',
                'exch_seg' => 'NFO',
                'instrumenttype' => 'OPTIDX',
                'symbol' => 'NIFTY26NOV2626100PE',
                'token' => '102',
                'expiry' => '26NOV2026',
                'strike' => '2610000.000000',
            ],
        ];
        $scanner = new PaperTradingScanner(new AngelOneClient, new HeikinAshiSupertrend);

        $options = $scanner->atmOptions(
            $instruments,
            26112,
            CarbonImmutable::parse('2026-11-17 11:30:00', 'Asia/Kolkata'),
        );

        $this->assertSame(26100, $options['strike']);
        $this->assertSame('101', $options['ce']['token']);
        $this->assertSame('102', $options['pe']['token']);
        $this->assertSame('2026-11-26', $options['expiry']->format('Y-m-d'));
    }

    public function test_it_waits_for_the_directional_entry_threshold_and_exits_at_the_favorable_target(): void
    {
        config([
            'cache.default' => 'array',
            'angelone.atr_period' => 2,
            'angelone.supertrend_multiplier' => 0.2,
            'angelone.reference_offset_percent' => 0.10,
            'angelone.paper_lots' => 1,
        ]);
        $ltpPrices = [100.0, 80.0];
        $ltpRequests = [];
        $angelOne = $this->createMock(AngelOneClient::class);
        $angelOne->expects($this->exactly(2))
            ->method('ltp')
            ->willReturnCallback(function (string $exchange, string $symbol, string $token) use (&$ltpPrices, &$ltpRequests): float {
                $ltpRequests[] = [$exchange, $symbol, $token];

                return array_shift($ltpPrices);
            });
        $scanner = new PaperTradingScanner($angelOne, new HeikinAshiSupertrend);
        $future = ['symbol' => 'NIFTY-FUT', 'token' => '61471'];
        $candles = [
            ['2026-11-17T09:15:00+05:30', 25500, 25510, 25490, 25505],
            ['2026-11-17T09:20:00+05:30', 25505, 25520, 25500, 25515],
            ['2026-11-17T09:25:00+05:30', 25515, 25530, 25510, 25525],
            ['2026-11-17T09:30:00+05:30', 25525, 25540, 25520, 25535],
            ['2026-11-17T09:35:00+05:30', 25535, 25540, 25400, 25410],
        ];
        $instruments = $this->monthlyOptionInstruments(25400);

        $signal = $scanner->processClosedCandles(
            $candles,
            $future,
            CarbonImmutable::parse('2026-11-17 09:40:00', 'Asia/Kolkata'),
        );

        $this->assertSame('pending_entry', $signal['status']);
        $this->assertSame('bearish', $signal['direction']);
        $this->assertSame(25384.59, $signal['entry_trigger_price']);

        $beforeThreshold = $scanner->processFutureTick(
            25390.0,
            CarbonImmutable::parse('2026-11-17 09:40:01', 'Asia/Kolkata'),
            $future,
            $instruments,
        );
        $this->assertSame('pending_entry', $beforeThreshold['status']);

        $entry = $scanner->processFutureTick(
            25380.0,
            CarbonImmutable::parse('2026-11-17 09:40:02', 'Asia/Kolkata'),
            $future,
            $instruments,
        );
        $this->assertSame('signal', $entry['status']);
        $this->assertSame('NIFTY26NOV2625400CE', $entry['option_symbol']);
        $this->assertSame(25354.62, $entry['target_futures_price']);

        $exit = $scanner->processFutureTick(
            25354.0,
            CarbonImmutable::parse('2026-11-17 09:41:00', 'Asia/Kolkata'),
            $future,
            $instruments,
        );

        $this->assertSame('exit', $exit['status']);
        $this->assertSame([
            ['NFO', 'NIFTY26NOV2625400CE', '101'],
            ['NFO', 'NIFTY26NOV2625400CE', '101'],
        ], $ltpRequests);
        $this->assertDatabaseHas('paper_trades', [
            'direction' => 'CE',
            'entry_futures_price' => 25380,
            'futures_target_price' => 25354.62,
            'exit_futures_price' => 25354,
            'status' => 'closed',
            'realized_pnl' => 500,
            'exit_reason' => 'NIFTY futures reached the 0.10% favorable target',
        ]);
        $this->assertDatabaseHas('paper_alerts', [
            'direction' => 'bearish',
            'futures_price' => 25410,
            'reference_price' => 25435.41,
            'entry_futures_price' => 25380,
            'entry_trigger_price' => 25384.59,
            'expiry' => '2026-11-26',
        ]);
    }

    public function test_bullish_flip_enters_monthly_pe_after_the_directional_threshold(): void
    {
        config([
            'cache.default' => 'array',
            'angelone.atr_period' => 2,
            'angelone.supertrend_multiplier' => 0.2,
            'angelone.reference_offset_percent' => 0.10,
        ]);
        $angelOne = $this->createMock(AngelOneClient::class);
        $angelOne->expects($this->once())
            ->method('ltp')
            ->with('NFO', 'NIFTY26NOV2625550PE', '102')
            ->willReturn(90.0);
        $scanner = new PaperTradingScanner($angelOne, new HeikinAshiSupertrend);
        $future = ['symbol' => 'NIFTY-FUT', 'token' => '61471'];
        $instruments = $this->monthlyOptionInstruments(25550);
        $candles = [
            ['2026-11-17T09:15:00+05:30', 25400, 25420, 25390, 25400],
            ['2026-11-17T09:20:00+05:30', 25400, 25410, 25380, 25390],
            ['2026-11-17T09:25:00+05:30', 25390, 25400, 25370, 25380],
            ['2026-11-17T09:30:00+05:30', 25380, 25390, 25360, 25370],
            ['2026-11-17T09:35:00+05:30', 25370, 25520, 25360, 25510],
        ];

        $signal = $scanner->processClosedCandles(
            $candles,
            $future,
            CarbonImmutable::parse('2026-11-17 09:40:00', 'Asia/Kolkata'),
        );

        $this->assertSame('pending_entry', $signal['status']);
        $this->assertSame('bullish', $signal['direction']);
        $this->assertSame(25535.51, $signal['entry_trigger_price']);

        $entry = $scanner->processFutureTick(
            25540.0,
            CarbonImmutable::parse('2026-11-17 09:40:01', 'Asia/Kolkata'),
            $future,
            $instruments,
        );

        $this->assertSame('signal', $entry['status']);
        $this->assertSame('PE', $entry['option_type']);
        $this->assertSame('NIFTY26NOV2625550PE', $entry['option_symbol']);
        $this->assertSame(25565.54, $entry['target_futures_price']);
    }

    public function test_it_can_refresh_a_historical_snapshot_without_processing_a_signal(): void
    {
        config([
            'cache.default' => 'array',
            'angelone.atr_period' => 2,
            'angelone.supertrend_multiplier' => 0.2,
        ]);
        $scanner = new PaperTradingScanner(new AngelOneClient, new HeikinAshiSupertrend);
        $candles = [
            ['2026-11-17T09:15:00+05:30', 100, 101, 99, 100],
            ['2026-11-17T09:16:00+05:30', 100, 102, 99, 101],
            ['2026-11-17T09:17:00+05:30', 101, 103, 100, 102],
            ['2026-11-17T09:18:00+05:30', 102, 104, 101, 103],
            ['2026-11-17T09:19:00+05:30', 103, 104, 70, 71],
        ];

        $result = $scanner->processClosedCandles(
            $candles,
            ['symbol' => 'NIFTY-FUT', 'token' => '61471'],
            CarbonImmutable::parse('2026-11-17 09:20:00', 'Asia/Kolkata'),
            false,
        );

        $this->assertSame('no_signal', $result['status']);
        $this->assertSame('bearish', $result['snapshot']['direction']);
        $this->assertSame($result['snapshot'], Cache::get('angelone.latest_snapshot'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monthlyOptionInstruments(int $strike): array
    {
        return [
            [
                'name' => 'NIFTY',
                'exch_seg' => 'NFO',
                'instrumenttype' => 'OPTIDX',
                'symbol' => "NIFTY26NOV26{$strike}CE",
                'token' => '101',
                'expiry' => '26NOV2026',
                'strike' => number_format($strike * 100, 6, '.', ''),
                'lotsize' => '25',
            ],
            [
                'name' => 'NIFTY',
                'exch_seg' => 'NFO',
                'instrumenttype' => 'OPTIDX',
                'symbol' => "NIFTY26NOV26{$strike}PE",
                'token' => '102',
                'expiry' => '26NOV2026',
                'strike' => number_format($strike * 100, 6, '.', ''),
                'lotsize' => '25',
            ],
        ];
    }
}

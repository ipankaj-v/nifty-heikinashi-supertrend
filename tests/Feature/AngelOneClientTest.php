<?php

namespace Tests\Feature;

use App\Services\AngelOneClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AngelOneClientTest extends TestCase
{
    public function test_it_logs_in_and_fetches_five_minute_future_candles(): void
    {
        config([
            'app.key' => str_repeat('a', 32),
            'angelone.api_key' => 'test-api-key',
            'angelone.client_code' => 'test-client',
            'angelone.pin' => '1234',
            'angelone.totp_secret' => 'JBSWY3DPEHPK3PXP',
            'angelone.client_local_ip' => '192.168.1.2',
            'angelone.client_public_ip' => '203.0.113.10',
            'angelone.mac_address' => '01:23:45:67:89:ab',
        ]);
        Cache::flush();
        Http::fake([
            '*loginByPassword' => Http::response([
                'status' => true,
                'data' => ['jwtToken' => 'test-jwt-token'],
            ]),
            '*getCandleData' => Http::response([
                'status' => true,
                'data' => [['2026-11-17T09:15:00+05:30', 100, 102, 99, 101, 50]],
            ]),
        ]);

        $candles = (new AngelOneClient)->candles('12345', '2026-11-17 09:15', '2026-11-17 09:16');

        $this->assertSame([['2026-11-17T09:15:00+05:30', 100, 102, 99, 101, 50]], $candles);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/loginByPassword')
            && $request['clientcode'] === 'test-client'
            && preg_match('/^\d{6}$/', $request['totp']) === 1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/getCandleData')
            && $request['exchange'] === 'NFO'
            && $request['symboltoken'] === '12345'
            && $request['interval'] === 'FIVE_MINUTE'
            && $request->hasHeader('Authorization', 'Bearer test-jwt-token'));
    }

    public function test_it_returns_websocket_credentials_from_the_angel_one_session(): void
    {
        config([
            'app.key' => str_repeat('a', 32),
            'angelone.api_key' => 'test-api-key',
            'angelone.client_code' => 'test-client',
            'angelone.pin' => '1234',
            'angelone.totp_secret' => 'JBSWY3DPEHPK3PXP',
            'angelone.client_local_ip' => '192.168.1.2',
            'angelone.client_public_ip' => '203.0.113.10',
            'angelone.mac_address' => '01:23:45:67:89:ab',
        ]);
        Cache::flush();
        Http::fake([
            '*loginByPassword' => Http::response([
                'status' => true,
                'data' => ['jwtToken' => 'test-jwt-token', 'feedToken' => 'test-feed-token'],
            ]),
        ]);

        $credentials = (new AngelOneClient)->websocketCredentials();

        $this->assertSame([
            'jwt_token' => 'test-jwt-token',
            'feed_token' => 'test-feed-token',
        ], $credentials);
    }

    public function test_it_reauthenticates_once_when_the_market_data_token_expires(): void
    {
        config([
            'app.key' => str_repeat('a', 32),
            'angelone.api_key' => 'test-api-key',
            'angelone.client_code' => 'test-client',
            'angelone.pin' => '1234',
            'angelone.totp_secret' => 'JBSWY3DPEHPK3PXP',
            'angelone.client_local_ip' => '192.168.1.2',
            'angelone.client_public_ip' => '203.0.113.10',
            'angelone.mac_address' => '01:23:45:67:89:ab',
        ]);
        Cache::flush();
        $loginCalls = 0;
        $candleCalls = 0;

        Http::fake(function (Request $request) use (&$loginCalls, &$candleCalls) {
            if (str_ends_with($request->url(), '/loginByPassword')) {
                $loginCalls++;

                return Http::response([
                    'status' => true,
                    'data' => ['jwtToken' => 'test-jwt-token-'.$loginCalls],
                ]);
            }

            if (str_ends_with($request->url(), '/getCandleData')) {
                $candleCalls++;

                if ($candleCalls === 1) {
                    return Http::response(['error_type' => 'TokenException'], 403);
                }

                return Http::response([
                    'status' => true,
                    'data' => [['2026-11-17T09:15:00+05:30', 100, 102, 99, 101, 50]],
                ]);
            }

            return Http::response([], 404);
        });

        $candles = (new AngelOneClient)->candles('12345', '2026-11-17 09:15', '2026-11-17 09:16');

        $this->assertCount(1, $candles);
        $this->assertSame(2, $loginCalls);
        $this->assertSame(2, $candleCalls);
    }

    public function test_it_streams_and_keeps_only_nifty_future_and_option_instruments(): void
    {
        Cache::flush();
        Http::fake([
            '*' => Http::response([
                [
                    'name' => 'NIFTY',
                    'exch_seg' => 'NFO',
                    'instrumenttype' => 'FUTIDX',
                    'symbol' => 'NIFTY-FUT',
                    'token' => '101',
                ],
                [
                    'name' => 'NIFTY',
                    'exch_seg' => 'NFO',
                    'instrumenttype' => 'OPTIDX',
                    'symbol' => 'NIFTY-CE',
                    'token' => '102',
                ],
                [
                    'name' => 'BANKNIFTY',
                    'exch_seg' => 'NFO',
                    'instrumenttype' => 'FUTIDX',
                    'symbol' => 'BANKNIFTY-FUT',
                    'token' => '201',
                ],
                [
                    'name' => 'NIFTY',
                    'exch_seg' => 'NSE',
                    'instrumenttype' => 'AMXIDX',
                    'symbol' => 'NIFTY',
                    'token' => '301',
                ],
            ]),
        ]);

        $instruments = (new AngelOneClient)->instruments();

        $this->assertSame(['101', '102'], array_column($instruments, 'token'));
    }

    public function test_it_does_not_retry_a_rate_limited_forbidden_candle_request(): void
    {
        config([
            'app.key' => str_repeat('a', 32),
            'angelone.api_key' => 'test-api-key',
            'angelone.client_code' => 'test-client',
            'angelone.pin' => '1234',
            'angelone.totp_secret' => 'JBSWY3DPEHPK3PXP',
            'angelone.client_local_ip' => '192.168.1.2',
            'angelone.client_public_ip' => '203.0.113.10',
            'angelone.mac_address' => '01:23:45:67:89:ab',
        ]);
        Cache::flush();
        $candleCalls = 0;

        Http::fake(function (Request $request) use (&$candleCalls) {
            if (str_ends_with($request->url(), '/loginByPassword')) {
                return Http::response([
                    'status' => true,
                    'data' => ['jwtToken' => 'test-jwt-token'],
                ]);
            }

            if (str_ends_with($request->url(), '/getCandleData')) {
                $candleCalls++;

                return Http::response([
                    'status' => false,
                    'message' => 'Access denied because of exceeding access rate',
                ], 403);
            }

            return Http::response([], 404);
        });

        try {
            (new AngelOneClient)->candles('12345', '2026-11-17 09:15', '2026-11-17 09:16');
            $this->fail('The rate-limited candle request should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith('Angel One rate limit reached. Scanning paused until ', $exception->getMessage());
        }

        $this->assertSame(1, $candleCalls);
        $this->assertTrue((new AngelOneClient)->isRateLimited());

        try {
            (new AngelOneClient)->candles('12345', '2026-11-17 09:15', '2026-11-17 09:16');
            $this->fail('The active cooldown should block another candle request.');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith('Angel One rate limit cooldown is active until ', $exception->getMessage());
        }

        $this->assertSame(1, $candleCalls);
    }

    public function test_historical_candle_cooldown_does_not_block_option_ltp_requests(): void
    {
        config([
            'app.key' => str_repeat('a', 32),
            'angelone.api_key' => 'test-api-key',
            'angelone.client_code' => 'test-client',
            'angelone.pin' => '1234',
            'angelone.totp_secret' => 'JBSWY3DPEHPK3PXP',
            'angelone.client_local_ip' => '192.168.1.2',
            'angelone.client_public_ip' => '203.0.113.10',
            'angelone.mac_address' => '01:23:45:67:89:ab',
        ]);
        Cache::flush();
        Cache::put('angelone.rate-limit-until', now()->addMinutes(15)->toIso8601String(), now()->addMinutes(15));
        Http::fake([
            '*loginByPassword' => Http::response([
                'status' => true,
                'data' => ['jwtToken' => 'test-jwt-token'],
            ]),
            '*getLtpData' => Http::response([
                'status' => true,
                'data' => ['ltp' => 125.5],
            ]),
        ]);

        $price = (new AngelOneClient)->ltp('NFO', 'NIFTY-CE', '12345');

        $this->assertSame(125.5, $price);
    }
}

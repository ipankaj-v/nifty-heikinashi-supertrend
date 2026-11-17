<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PaperTradingDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'angelone.api_key' => null,
            'angelone.client_code' => null,
            'angelone.pin' => null,
            'angelone.totp_secret' => null,
            'angelone.client_local_ip' => null,
            'angelone.client_public_ip' => null,
            'angelone.mac_address' => null,
        ]);
    }

    public function test_it_shows_the_paper_trading_dashboard(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('PAPER MODE')
            ->assertSee('Connect Angel One SmartAPI')
            ->assertSee('php artisan angelone:stream')
            ->assertSee('5-minute Heikin Ashi')
            ->assertSee('Live feed · 5-minute candles')
            ->assertSee('sell monthly CE')
            ->assertSee('further 0.10% in the favorable direction')
            ->assertSee('Enable flip sound')
            ->assertSee('Supertrend value')
            ->assertSee('Last closed Heikin Ashi candle · opened')
            ->assertSee('Futures candle close + 0.10%')
            ->assertSee('historical backfill retry at')
            ->assertSee('ATM CE')
            ->assertSee('ATM PE');
    }

    public function test_dashboard_data_is_available_without_angel_one_credentials(): void
    {
        $this->getJson('/dashboard/data')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('alerts', [])
            ->assertJsonPath('trades', [])
            ->assertJsonPath('flip_events', []);
    }

    public function test_dashboard_data_includes_recorded_supertrend_flips(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();
        Cache::put('angelone.flip_events', [
            [
                'id' => 'flip-123',
                'direction' => 'bullish',
                'option_type' => 'PE',
                'signal_time' => '2026-11-17T09:30:00+05:30',
            ],
        ]);

        $this->getJson('/dashboard/data')
            ->assertOk()
            ->assertJsonPath('flip_events.0.id', 'flip-123')
            ->assertJsonPath('flip_events.0.direction', 'bullish');
    }

    public function test_manual_rest_scan_endpoint_is_removed_for_websocket_streaming(): void
    {
        $this->postJson('/dashboard/scan')
            ->assertNotFound();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\PaperAlert;
use App\Models\PaperTrade;
use App\Services\AngelOneClient;
use App\Services\FiveMinuteCandleAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class PaperTradingController extends Controller
{
    public function index(AngelOneClient $angelOne): View
    {
        return view('dashboard', [
            'isConfigured' => $angelOne->isConfigured(),
            'settings' => [
                'timeframe' => FiveMinuteCandleAggregator::INTERVAL_MINUTES.'-minute',
                'atrPeriod' => config('angelone.atr_period'),
                'multiplier' => config('angelone.supertrend_multiplier'),
                'referenceOffset' => config('angelone.reference_offset_percent'),
                'paperLots' => config('angelone.paper_lots'),
            ],
        ]);
    }

    public function dashboard(AngelOneClient $angelOne): JsonResponse
    {
        return response()->json([
            'configured' => $angelOne->isConfigured(),
            'snapshot' => Cache::get('angelone.latest_snapshot'),
            'last_scan_at' => Cache::get('angelone.last_scan_at'),
            'stream_status' => Cache::get('angelone.stream.status'),
            'flip_events' => Cache::get('angelone.flip_events', []),
            'alerts' => PaperAlert::query()->latest('signal_time')->limit(20)->get(),
            'trades' => PaperTrade::query()->latest('signal_time')->limit(20)->get(),
            'open_trade' => PaperTrade::query()->where('status', 'open')->latest('signal_time')->first(),
            'realized_pnl' => (float) PaperTrade::query()->where('status', 'closed')->sum('realized_pnl'),
        ]);
    }
}

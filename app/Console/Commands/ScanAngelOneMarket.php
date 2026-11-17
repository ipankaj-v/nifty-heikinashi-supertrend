<?php

namespace App\Console\Commands;

use App\Services\PaperTradingScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('angelone:scan')]
#[Description('Scan closed candles; use the stream worker for live entry and exit triggers')]
class ScanAngelOneMarket extends Command
{
    public function handle(PaperTradingScanner $scanner): int
    {
        $result = $scanner->scan();

        $this->info('Scan result: '.($result['status'] ?? 'unknown'));

        if (($result['status'] ?? null) === 'pending_entry') {
            $this->info("Waiting for NIFTY futures to reach {$result['entry_trigger_price']}; use php artisan angelone:stream for live monitoring.");
        }

        return self::SUCCESS;
    }
}

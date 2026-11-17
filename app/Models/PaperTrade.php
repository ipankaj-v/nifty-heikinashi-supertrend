<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperTrade extends Model
{
    protected $fillable = [
        'direction',
        'symbol',
        'token',
        'expiry',
        'strike',
        'quantity',
        'entry_price',
        'entry_futures_price',
        'futures_target_price',
        'signal_time',
        'entry_time',
        'status',
        'exit_price',
        'exit_futures_price',
        'realized_pnl',
        'exit_time',
        'exit_reason',
    ];

    protected function casts(): array
    {
        return [
            'entry_price' => 'decimal:2',
            'entry_futures_price' => 'decimal:2',
            'futures_target_price' => 'decimal:2',
            'exit_price' => 'decimal:2',
            'exit_futures_price' => 'decimal:2',
            'realized_pnl' => 'decimal:2',
            'signal_time' => 'immutable_datetime',
            'entry_time' => 'immutable_datetime',
            'exit_time' => 'immutable_datetime',
        ];
    }
}

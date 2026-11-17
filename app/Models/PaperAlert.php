<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperAlert extends Model
{
    protected $fillable = [
        'direction',
        'futures_symbol',
        'futures_token',
        'futures_price',
        'reference_price',
        'entry_futures_price',
        'entry_trigger_price',
        'option_type',
        'option_symbol',
        'option_token',
        'expiry',
        'strike',
        'option_price',
        'signal_time',
        'entry_time',
        'paper_trade_id',
    ];

    protected function casts(): array
    {
        return [
            'futures_price' => 'decimal:2',
            'reference_price' => 'decimal:2',
            'entry_futures_price' => 'decimal:2',
            'entry_trigger_price' => 'decimal:2',
            'option_price' => 'decimal:2',
            'signal_time' => 'immutable_datetime',
            'entry_time' => 'immutable_datetime',
        ];
    }
}

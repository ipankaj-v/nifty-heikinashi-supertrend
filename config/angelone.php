<?php

return [
    'api_key' => env('ANGELONE_API_KEY'),
    'client_code' => env('ANGELONE_CLIENT_CODE'),
    'pin' => env('ANGELONE_PIN'),
    'totp_secret' => env('ANGELONE_TOTP_SECRET'),
    'client_local_ip' => env('ANGELONE_CLIENT_LOCAL_IP'),
    'client_public_ip' => env('ANGELONE_CLIENT_PUBLIC_IP'),
    'mac_address' => env('ANGELONE_MAC_ADDRESS'),
    'paper_lots' => (int) env('ANGELONE_PAPER_LOTS', 1),
    'timezone' => 'Asia/Kolkata',
    'instrument_master_url' => 'https://margincalculator.angelbroking.com/OpenAPI_File/files/OpenAPIScripMaster.json',
    'base_url' => 'https://apiconnect.angelone.in',
    'atr_period' => 10,
    'supertrend_multiplier' => 3.0,
    'reference_offset_percent' => 0.10,
    'rate_limit_cooldown_minutes' => 15,
];

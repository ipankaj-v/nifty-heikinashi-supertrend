<?php

use App\Http\Controllers\PaperTradingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PaperTradingController::class, 'index'])->name('dashboard');
Route::get('/dashboard/data', [PaperTradingController::class, 'dashboard'])->name('dashboard.data');

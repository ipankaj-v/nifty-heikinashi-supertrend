<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('paper_trades', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 4);
            $table->string('symbol');
            $table->string('token');
            $table->string('expiry');
            $table->unsignedInteger('strike');
            $table->unsignedInteger('quantity');
            $table->decimal('entry_price', 12, 2);
            $table->dateTime('signal_time');
            $table->string('status', 10)->default('open');
            $table->decimal('exit_price', 12, 2)->nullable();
            $table->decimal('realized_pnl', 14, 2)->nullable();
            $table->dateTime('exit_time')->nullable();
            $table->string('exit_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paper_trades');
    }
};

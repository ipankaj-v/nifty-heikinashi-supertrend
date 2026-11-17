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
        Schema::create('paper_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10);
            $table->string('futures_symbol');
            $table->string('futures_token');
            $table->decimal('futures_price', 12, 2);
            $table->decimal('reference_price', 12, 2);
            $table->string('option_type', 2);
            $table->string('option_symbol');
            $table->string('option_token');
            $table->string('expiry');
            $table->unsignedInteger('strike');
            $table->decimal('option_price', 12, 2);
            $table->dateTime('signal_time');
            $table->foreignId('paper_trade_id')->nullable()->constrained('paper_trades')->nullOnDelete();
            $table->unique(['signal_time', 'direction']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paper_alerts');
    }
};

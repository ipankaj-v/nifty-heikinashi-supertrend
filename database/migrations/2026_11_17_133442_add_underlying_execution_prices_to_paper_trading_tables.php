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
        Schema::table('paper_trades', function (Blueprint $table) {
            $table->decimal('entry_futures_price', 12, 2)->nullable();
            $table->decimal('futures_target_price', 12, 2)->nullable();
            $table->decimal('exit_futures_price', 12, 2)->nullable();
            $table->dateTime('entry_time')->nullable();
        });

        Schema::table('paper_alerts', function (Blueprint $table) {
            $table->decimal('entry_futures_price', 12, 2)->nullable();
            $table->decimal('entry_trigger_price', 12, 2)->nullable();
            $table->dateTime('entry_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paper_alerts', function (Blueprint $table) {
            $table->dropColumn(['entry_futures_price', 'entry_trigger_price', 'entry_time']);
        });

        Schema::table('paper_trades', function (Blueprint $table) {
            $table->dropColumn([
                'entry_futures_price',
                'futures_target_price',
                'exit_futures_price',
                'entry_time',
            ]);
        });
    }
};

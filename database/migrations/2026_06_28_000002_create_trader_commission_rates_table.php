<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trader_commission_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            $table->string('operation_type', 16);
            $table->unsignedBigInteger('min_amount')->nullable();
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->float('trader_commission_rate', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(
                ['user_id', 'payment_gateway_id', 'operation_type'],
                'trader_commission_rates_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trader_commission_rates');
    }
};

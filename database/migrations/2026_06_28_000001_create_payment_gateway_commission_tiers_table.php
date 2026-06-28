<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_commission_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            $table->string('operation_type', 16);
            $table->unsignedBigInteger('min_amount');
            $table->unsignedBigInteger('max_amount');
            $table->float('trader_commission_rate', 8, 2);
            $table->float('total_service_commission_rate', 8, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(
                ['payment_gateway_id', 'operation_type', 'min_amount', 'max_amount'],
                'pg_commission_tiers_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_commission_tiers');
    }
};

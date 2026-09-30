<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_variant_qr_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qr_type_id')->constrained()->cascadeOnDelete();
            $table->unique(['vehicle_variant_id', 'qr_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_variant_qr_type');
    }
};

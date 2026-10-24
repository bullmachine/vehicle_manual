<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which QR types (Domestic, Export, ...) each FERT belongs to. A machine only opens
 * from a QR code of a type its FERT is mapped to; a FERT with no mapping opens from any.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fert_qr_type', function (Blueprint $table) {
            $table->foreignId('fert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qr_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['fert_id', 'qr_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fert_qr_type');
    }
};

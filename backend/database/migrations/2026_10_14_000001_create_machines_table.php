<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local copy of machine records synced from the SAP machine API (or loaded from the
 * dummy JSON file). Customer serial number lookups read this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('msno', 60)->unique();
            $table->string('fert', 40)->index();
            $table->string('description')->nullable();
            $table->string('invoice', 40)->nullable();
            $table->string('plant', 20)->nullable()->index();
            $table->date('delivery_date')->nullable();
            $table->string('source', 20)->default('sap');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'qr_codes' => true,
        'customer_sessions' => false,
        'otp_verifications' => false,
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $nullable) {
            Schema::table($table, function (Blueprint $blueprint) use ($nullable) {
                $blueprint->dateTime('expires_at')->nullable($nullable)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $nullable) {
            Schema::table($table, function (Blueprint $blueprint) use ($nullable) {
                $blueprint->timestamp('expires_at')->nullable($nullable)->change();
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers now identify their machine by serial number (MSNO). The serial number
 * resolves to a FERT material number, and manuals are mapped to FERTs instead of
 * vehicle variant + engine. OTP verification and QR type filtering are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ferts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('fert_manual', function (Blueprint $table) {
            $table->foreignId('fert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manual_id')->constrained()->cascadeOnDelete();
            $table->primary(['fert_id', 'manual_id']);
        });

        Schema::dropIfExists('manual_qr_type');
        Schema::dropIfExists('vehicle_variant_qr_type');
        Schema::dropIfExists('vehicle_engine_mappings');
        Schema::dropIfExists('otp_verifications');

        Schema::table('manuals', function (Blueprint $table) {
            $table->dropForeign(['vehicle_variant_id']);
            $table->dropForeign(['engine_id']);
        });
        Schema::table('manuals', function (Blueprint $table) {
            $table->dropIndex(['vehicle_variant_id', 'engine_id', 'language_id', 'status']);
            $table->dropColumn(['vehicle_variant_id', 'engine_id']);
            $table->index(['language_id', 'status']);
        });

        Schema::table('qr_codes', function (Blueprint $table) {
            $table->dropForeign(['vehicle_variant_id']);
            $table->dropForeign(['engine_id']);
            $table->dropForeign(['language_id']);
        });
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->dropColumn(['vehicle_variant_id', 'engine_id', 'language_id']);
        });

        Schema::table('customer_sessions', function (Blueprint $table) {
            $table->dropForeign(['vehicle_variant_id']);
            $table->dropForeign(['engine_id']);
        });
        Schema::table('customer_sessions', function (Blueprint $table) {
            $table->dropColumn(['vehicle_variant_id', 'engine_id']);
        });
        Schema::table('customer_sessions', function (Blueprint $table) {
            $table->foreignId('fert_id')->nullable()->after('qr_code_id')->constrained()->restrictOnDelete();
            $table->string('machine_serial_no', 60)->nullable()->after('fert_id')->index();
        });

        Schema::table('qr_scan_logs', function (Blueprint $table) {
            $table->dropForeign(['vehicle_variant_id']);
            $table->dropForeign(['engine_id']);
        });
        Schema::table('qr_scan_logs', function (Blueprint $table) {
            $table->dropColumn(['vehicle_variant_id', 'engine_id']);
        });
        Schema::table('qr_scan_logs', function (Blueprint $table) {
            $table->foreignId('fert_id')->nullable()->after('customer_session_id')->constrained()->nullOnDelete();
            $table->string('machine_serial_no', 60)->nullable()->after('fert_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('mobile', 20)->nullable()->change();
        });

        Schema::dropIfExists('engines');
        Schema::dropIfExists('vehicle_variants');
    }

    public function down(): void
    {
        Schema::create('vehicle_variants', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('engines', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::table('qr_scan_logs', function (Blueprint $table) {
            $table->dropForeign(['fert_id']);
        });
        Schema::table('qr_scan_logs', function (Blueprint $table) {
            $table->dropColumn(['fert_id', 'machine_serial_no']);
            $table->foreignId('vehicle_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('engine_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('customer_sessions', function (Blueprint $table) {
            $table->dropForeign(['fert_id']);
        });
        Schema::table('customer_sessions', function (Blueprint $table) {
            $table->dropIndex(['machine_serial_no']);
            $table->dropColumn(['fert_id', 'machine_serial_no']);
            $table->foreignId('vehicle_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('engine_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::table('qr_codes', function (Blueprint $table) {
            $table->foreignId('vehicle_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('engine_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('manuals', function (Blueprint $table) {
            $table->dropIndex(['language_id', 'status']);
            $table->foreignId('vehicle_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('engine_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['vehicle_variant_id', 'engine_id', 'language_id', 'status']);
        });

        Schema::create('vehicle_engine_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engine_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['vehicle_variant_id', 'engine_id']);
        });

        Schema::create('vehicle_variant_qr_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qr_type_id')->constrained()->cascadeOnDelete();
            $table->unique(['vehicle_variant_id', 'qr_type_id']);
        });

        Schema::create('manual_qr_type', function (Blueprint $table) {
            $table->foreignId('manual_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qr_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['manual_id', 'qr_type_id']);
        });

        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 20)->index();
            $table->string('otp_hash', 64);
            $table->dateTime('expires_at')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('request_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['mobile', 'created_at']);
        });

        Schema::dropIfExists('fert_manual');
        Schema::dropIfExists('ferts');
    }
};

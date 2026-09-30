<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('id')->constrained('roles')->nullOnDelete();
            $table->string('username')->nullable()->unique()->after('email');
            $table->string('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->timestamp('last_login_at')->nullable()->after('two_factor_confirmed_at');
            $table->boolean('is_active')->default(true)->after('last_login_at');
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

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

        Schema::create('vehicle_engine_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engine_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['vehicle_variant_id', 'engine_id']);
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('native_name')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('manual_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('qr_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('manuals', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('manual_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('engine_id')->constrained()->restrictOnDelete();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->boolean('show_old_versions')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_variant_id', 'engine_id', 'language_id', 'status']);
        });

        Schema::create('manual_qr_type', function (Blueprint $table) {
            $table->foreignId('manual_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qr_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['manual_id', 'qr_type_id']);
        });

        Schema::create('manual_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manual_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->date('effective_date')->nullable();
            $table->text('description')->nullable();
            $table->string('file_disk')->default('local');
            $table->string('file_path');
            $table->text('search_text')->nullable();
            $table->string('original_name');
            $table->string('mime_type')->default('application/pdf');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('page_count')->default(0);
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['manual_id', 'version']);
        });

        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('token_hash', 64)->unique();
            $table->text('token_ciphertext');
            $table->foreignId('qr_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('engine_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->string('vehicle_number')->nullable();
            $table->string('chassis_number')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedInteger('token_version')->default(1);
            $table->unsignedBigInteger('scan_count')->default(0);
            $table->timestamp('last_scanned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['qr_type_id', 'status']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mobile', 20)->unique();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_token_hash', 64)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qr_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('engine_id')->constrained()->restrictOnDelete();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 20)->index();
            $table->string('otp_hash', 64);
            $table->timestamp('expires_at')->index();
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

        Schema::create('qr_scan_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qr_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('engine_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device')->nullable();
            $table->timestamps();
            $table->index(['qr_code_id', 'created_at']);
        });

        Schema::create('manual_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manual_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manual_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_session_id')->constrained()->cascadeOnDelete();
            $table->string('event')->index();
            $table->unsignedInteger('page_number')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['manual_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('manual_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manual_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rating')->nullable();
            $table->string('issue_type')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('module')->index();
            $table->string('record_type')->nullable();
            $table->string('record_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('manual_feedback');
        Schema::dropIfExists('manual_access_logs');
        Schema::dropIfExists('qr_scan_logs');
        Schema::dropIfExists('otp_verifications');
        Schema::dropIfExists('customer_sessions');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('qr_codes');
        Schema::dropIfExists('manual_versions');
        Schema::dropIfExists('manual_qr_type');
        Schema::dropIfExists('manuals');
        Schema::dropIfExists('qr_types');
        Schema::dropIfExists('manual_types');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('vehicle_engine_mappings');
        Schema::dropIfExists('engines');
        Schema::dropIfExists('vehicle_variants');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn(['role_id', 'username', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'last_login_at', 'is_active']);
        });
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};

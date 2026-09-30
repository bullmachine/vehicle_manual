<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('mobile', 20);
            $table->string('email', 160)->nullable();
            $table->string('subject', 160);
            $table->text('message');
            $table->string('status', 20)->default('new');
            $table->string('source', 60)->default('portal_footer');
            $table->foreignId('qr_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('mobile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_enquiries');
    }
};

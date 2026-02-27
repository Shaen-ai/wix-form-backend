<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->foreignId('tenant_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('notification_email')->nullable();
            $table->boolean('auto_reply_enabled')->default(false);
            $table->string('auto_reply_subject')->nullable();
            $table->text('auto_reply_body')->nullable();
            $table->boolean('recaptcha_enabled')->default(true);
            $table->string('recaptcha_mode')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};

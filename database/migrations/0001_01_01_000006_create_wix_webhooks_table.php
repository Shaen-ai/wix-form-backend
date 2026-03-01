<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wix_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('instance')->nullable();
            $table->string('origin_instance')->nullable();
            $table->string('user_id')->nullable();
            $table->json('content')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wix_webhooks');
    }
};

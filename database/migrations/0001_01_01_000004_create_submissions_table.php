<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->timestamp('submitted_at');
            $table->string('ip_hash')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('data_json');
            $table->string('wix_contact_id')->nullable();
            $table->timestamps();

            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};

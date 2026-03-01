<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id')->index();
            $table->string('comp_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('settings_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->string('language', 10)->nullable();
            $table->string('plan', 20)->default('free');
            $table->timestamps();

            $table->unique(['instance_id', 'comp_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};

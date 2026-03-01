<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('label')->default('');
            $table->text('help_text')->nullable();
            $table->string('placeholder')->nullable();
            $table->boolean('required')->default(false);
            $table->json('options_json')->nullable();
            $table->json('validation_json')->nullable();
            $table->json('logic_json')->nullable();
            $table->unsignedInteger('order_index')->default(0);
            $table->boolean('is_hidden_label')->default(false);
            $table->text('default_value')->nullable();
            $table->string('width', 10)->default('100');
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('page_index')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};

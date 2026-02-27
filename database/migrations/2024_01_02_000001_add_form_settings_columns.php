<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->enum('status', ['draft', 'published'])->default('draft')->after('is_active');
            $table->string('language', 10)->nullable()->after('status');
        });

        Schema::table('form_fields', function (Blueprint $table) {
            $table->text('default_value')->nullable()->after('is_hidden_label');
            $table->string('width', 10)->default('100')->after('default_value');
            $table->boolean('is_hidden')->default(false)->after('width');
            $table->unsignedInteger('page_index')->default(0)->after('is_hidden');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn(['status', 'language']);
        });

        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropColumn(['default_value', 'width', 'is_hidden', 'page_index']);
        });
    }
};

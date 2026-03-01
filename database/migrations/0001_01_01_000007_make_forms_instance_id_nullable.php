<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropUnique(['instance_id', 'comp_id']);
            $table->string('instance_id')->nullable()->change();
            $table->unique('comp_id');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropUnique(['comp_id']);
            $table->string('instance_id')->nullable(false)->change();
            $table->unique(['instance_id', 'comp_id']);
        });
    }
};

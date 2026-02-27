<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convert existing 'premium' plans to 'business_pro'
        DB::table('tenants')
            ->where('plan', 'premium')
            ->update(['plan' => 'business_pro']);

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan', 20)->default('free')->change();
        });
    }

    public function down(): void
    {
        DB::table('tenants')
            ->whereIn('plan', ['light', 'business', 'business_pro'])
            ->update(['plan' => 'premium']);

        Schema::table('tenants', function (Blueprint $table) {
            $table->enum('plan', ['free', 'premium'])->default('free')->change();
        });
    }
};

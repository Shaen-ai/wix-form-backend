<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_settings', function (Blueprint $table) {
            $table->dropColumn(['recaptcha_enabled', 'recaptcha_mode']);
        });
    }

    public function down(): void
    {
        Schema::table('form_settings', function (Blueprint $table) {
            $table->boolean('recaptcha_enabled')->default(false)->after('auto_reply_body');
            $table->string('recaptcha_mode')->nullable()->after('recaptcha_enabled');
        });
    }
};

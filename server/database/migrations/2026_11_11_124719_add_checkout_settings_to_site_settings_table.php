<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('checkout_grace_minutes')->default(60);
            $table->string('checkout_cutoff_time', 5)->default('23:59');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['checkout_grace_minutes', 'checkout_cutoff_time']);
        });
    }
};

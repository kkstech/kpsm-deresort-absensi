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
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('base_salary')->nullable();
            $table->string('religion', 40)->nullable();
            $table->string('place_of_birth', 120)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('marital_status', 32)->nullable();
            $table->text('permanent_address')->nullable();
            $table->text('current_address')->nullable();
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('retirement_age_years')->nullable();
        });

        Schema::table('device_registrations', function (Blueprint $table) {
            $table->string('expo_push_token', 255)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_registrations', function (Blueprint $table) {
            $table->dropUnique(['expo_push_token']);
            $table->dropColumn('expo_push_token');
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('retirement_age_years');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'base_salary',
                'religion',
                'place_of_birth',
                'date_of_birth',
                'gender',
                'marital_status',
                'permanent_address',
                'current_address',
            ]);
        });
    }
};

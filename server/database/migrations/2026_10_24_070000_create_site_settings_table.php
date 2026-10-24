<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('site_name', 120)->default('Deresort Attendance');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->text('contact_address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->timestamps();
        });

        DB::table('site_settings')->insert([
            'id' => 1,
            'site_name' => 'Deresort Attendance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};

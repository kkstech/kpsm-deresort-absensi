<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('distance_meters');
            $table->boolean('is_mocked')->default(false)->after('photo_path');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('check_in_photo_path')->nullable()->after('provisional_out_at');
            $table->string('check_out_photo_path')->nullable()->after('check_in_photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'is_mocked']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['check_in_photo_path', 'check_out_photo_path']);
        });
    }
};

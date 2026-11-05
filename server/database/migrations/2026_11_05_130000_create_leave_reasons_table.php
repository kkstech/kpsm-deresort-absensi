<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('leave_reasons')->insert(array_map(
            fn (string $name): array => ['name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['Cuti Tahunan', 'Acara Keluarga', 'Istirahat / Liburan', 'Keperluan Pribadi'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_reasons');
    }
};

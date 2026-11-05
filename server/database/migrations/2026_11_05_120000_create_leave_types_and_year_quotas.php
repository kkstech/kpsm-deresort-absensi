<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('default_quota')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('leave_types')->insert([
            ['code' => 'regular', 'name' => 'Cuti Reguler', 'default_quota' => 12, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'day_off', 'name' => 'Deposit Off', 'default_quota' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $legacyCodes = DB::table('leave_requests')->select('type')->distinct()->pluck('type')
            ->merge(DB::table('leave_adjustments')->select('type')->distinct()->pluck('type'))
            ->filter()->unique()->values();
        foreach ($legacyCodes as $code) {
            if (DB::table('leave_types')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('leave_types')->insert([
                'code' => $code,
                'name' => Str::headline(str_replace('_', ' ', $code)),
                'default_quota' => 0,
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::create('leave_year_quotas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quota')->default(0);
            $table->timestamps();
            $table->unique(['leave_year_id', 'leave_type_id']);
        });

        $typeIds = DB::table('leave_types')->pluck('id', 'code');
        foreach (DB::table('leave_years')->get(['id', 'regular_quota', 'day_off_quota']) as $year) {
            foreach (['regular' => $year->regular_quota, 'day_off' => $year->day_off_quota] as $code => $quota) {
                DB::table('leave_year_quotas')->insert([
                    'leave_year_id' => $year->id,
                    'leave_type_id' => $typeIds[$code],
                    'quota' => $quota,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->index('type');
            $table->foreign('type')->references('code')->on('leave_types')->restrictOnDelete();
        });
        Schema::table('leave_adjustments', function (Blueprint $table): void {
            $table->index('type');
            $table->foreign('type')->references('code')->on('leave_types')->restrictOnDelete();
        });
        Schema::table('leave_years', function (Blueprint $table): void {
            $table->dropColumn(['regular_quota', 'day_off_quota']);
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropForeign(['type']);
            $table->dropIndex(['type']);
        });
        Schema::table('leave_adjustments', function (Blueprint $table): void {
            $table->dropForeign(['type']);
            $table->dropIndex(['type']);
        });

        Schema::table('leave_years', function (Blueprint $table): void {
            $table->unsignedSmallInteger('regular_quota')->default(12);
            $table->unsignedSmallInteger('day_off_quota')->default(4);
        });

        $typeIds = DB::table('leave_types')->pluck('id', 'code');
        foreach (DB::table('leave_years')->get(['id']) as $year) {
            foreach (['regular' => 'regular_quota', 'day_off' => 'day_off_quota'] as $code => $column) {
                $quota = DB::table('leave_year_quotas')
                    ->where('leave_year_id', $year->id)
                    ->where('leave_type_id', $typeIds[$code])
                    ->value('quota');
                if ($quota !== null) {
                    DB::table('leave_years')->where('id', $year->id)->update([$column => $quota]);
                }
            }
        }

        Schema::dropIfExists('leave_year_quotas');
        Schema::dropIfExists('leave_types');
    }
};

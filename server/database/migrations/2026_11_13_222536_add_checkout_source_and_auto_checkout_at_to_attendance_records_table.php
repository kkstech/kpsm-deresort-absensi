<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('checkout_source', 32)->nullable()->after('provisional_out_at');
            $table->dateTime('auto_checkout_at')->nullable()->after('checkout_source');
        });

        DB::table('attendance_records')->whereNotNull('provisional_out_at')->update([
            'checkout_source' => 'SYSTEM_AUTO',
            'auto_checkout_at' => DB::raw('provisional_out_at'),
        ]);

        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->where('action', 'attendance.auto_checkout')->whereNotNull('subject_id')
                ->select(['id', 'subject_id', 'after'])->orderBy('id')->chunkById(500, function ($logs): void {
                    foreach ($logs as $log) {
                        $snapshot = $this->decodeSnapshot($log->after);
                        $autoCheckoutAt = $snapshot['auto_checkout_at'] ?? $snapshot['provisional_out_at'] ?? null;
                        if (! $autoCheckoutAt) {
                            continue;
                        }

                        DB::table('attendance_records')->where('id', $log->subject_id)->whereNull('auto_checkout_at')->update([
                            'auto_checkout_at' => CarbonImmutable::parse($autoCheckoutAt)->utc()->format('Y-m-d H:i:s'),
                        ]);
                    }
                });
        }

        DB::table('attendance_records')->whereNull('check_out_event_id')->whereNull('check_out_at')
            ->whereNotNull('auto_checkout_at')->update(['checkout_source' => 'SYSTEM_AUTO']);
        DB::table('attendance_records')->whereNotNull('check_out_event_id')->update(['checkout_source' => 'EMPLOYEE']);
        DB::table('attendance_records')->whereNotNull('check_out_at')->whereNull('check_out_event_id')->update(['checkout_source' => 'HR_CORRECTION']);
        DB::table('attendance_records')->whereNull('check_out_event_id')->whereNotNull('auto_checkout_at')
            ->whereColumn('check_out_at', 'auto_checkout_at')->update(['checkout_source' => 'SYSTEM_AUTO']);

        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->whereIn('action', [
                'attendance.correction.applied',
                'attendance.correction.direct',
                'attendance.checkout.verified',
            ])
                ->whereNotNull('subject_id')->select(['id', 'subject_id', 'before', 'after'])->orderBy('id')->chunkById(500, function ($logs): void {
                    foreach ($logs as $log) {
                        $before = $this->decodeSnapshot($log->before);
                        $after = $this->decodeSnapshot($log->after);
                        if (! ($after['check_out_at'] ?? null)
                            || $this->normalizeDateTime($before['check_out_at'] ?? null) === $this->normalizeDateTime($after['check_out_at'])) {
                            continue;
                        }
                        if ($log->action === 'attendance.checkout.verified') {
                            $automaticCheckoutAt = $after['auto_checkout_at']
                                ?? $after['provisional_out_at']
                                ?? $before['provisional_out_at']
                                ?? null;
                            if ($this->normalizeDateTime($automaticCheckoutAt) === $this->normalizeDateTime($after['check_out_at'])) {
                                continue;
                            }
                        }

                        DB::table('attendance_records')->where('id', $log->subject_id)->update(['checkout_source' => 'HR_CORRECTION']);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['checkout_source', 'auto_checkout_at']);
        });
    }

    private function decodeSnapshot(mixed $snapshot): array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }

        if (! is_string($snapshot)) {
            return [];
        }

        $decoded = json_decode($snapshot, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeDateTime(?string $value): ?string
    {
        return $value ? CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s') : null;
    }
};

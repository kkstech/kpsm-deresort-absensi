<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['month', 'status', 'revision', 'finalized_at', 'finalized_by', 'reopened_at', 'reopened_by', 'reopen_reason'])]
class AttendancePeriod extends Model
{
    public static function assertOpen(string $workDate): void
    {
        $month = substr($workDate, 0, 7);
        if (static::query()->where('month', $month)->where('status', 'FINALIZED')->exists()) {
            throw new BusinessRuleException('PERIOD_LOCKED', "Rekap {$month} sudah final. Buka ulang periode dulu.");
        }
    }

    public static function invalidateFinalization(string $workDate, string $reason): void
    {
        $period = static::query()->where('month', substr($workDate, 0, 7))->lockForUpdate()->first();
        if (! $period || (! $period->finalized_at && $period->status !== 'FINALIZED')) {
            return;
        }

        $before = $period->attributesToArray();
        $period->update([
            'status' => 'OPEN',
            'revision' => $period->revision + 1,
            'finalized_at' => null,
            'finalized_by' => null,
        ]);
        Audit::log('report.period_finalization_invalidated', $period, $before, [
            ...$period->attributesToArray(),
            'reason' => $reason,
        ]);
    }

    protected function casts(): array
    {
        return [
            'finalized_at' => 'datetime',
            'reopened_at' => 'datetime',
            'revision' => 'integer',
        ];
    }
}

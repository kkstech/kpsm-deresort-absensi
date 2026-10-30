<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_type', 'department_id', 'employee_id', 'grace_minutes', 'use_location_check', 'late_permission_proof_required', 'correction_proof_required', 'effective_from', 'effective_to'])]
class AttendancePolicy extends Model
{
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Prioritas INDIVIDUAL > DEPARTMENT > GLOBAL, lalu yang paling baru berlaku. */
    public static function resolveFor(Employee $employee, string $date): array
    {
        $policy = static::query()
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->where(fn ($q) => $q->where('scope_type', 'global')
                ->orWhere(fn ($q) => $q->where('scope_type', 'department')->where('department_id', $employee->department_id))
                ->orWhere(fn ($q) => $q->where('scope_type', 'employee')->where('employee_id', $employee->id)))
            ->orderByRaw("case scope_type when 'employee' then 0 when 'department' then 1 else 2 end")
            ->orderByDesc('effective_from')
            ->first();

        return [
            'policy_id' => $policy?->id,
            'scope_type' => $policy?->scope_type,
            'grace_minutes' => $policy?->grace_minutes ?? 0,
            'use_location_check' => $policy?->use_location_check ?? false,
            'late_permission_proof_required' => $policy?->late_permission_proof_required ?? false,
            'correction_proof_required' => $policy?->correction_proof_required ?? false,
        ];
    }

    /** Aturan + titik lokasi yang berlaku hari ini, untuk sesi yang belum ada absensi. */
    public static function snapshotFor(Employee $employee, string $date): array
    {
        $policy = static::resolveFor($employee, $date);
        $location = $employee->relationLoaded('workLocation')
            ? $employee->workLocation
            : $employee->workLocation()->first();
        $hasRadiusLocation = $location?->mode === 'radius'
            && $location->latitude !== null
            && $location->longitude !== null
            && $location->radius_meters;
        $useLocationCheck = (bool) ($policy['use_location_check'] || $hasRadiusLocation);
        $policy['use_location_check'] = $useLocationCheck;
        $policy['location'] = $useLocationCheck && $location?->mode === 'radius' ? [
            'mode' => 'radius',
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'radius_meters' => $location->radius_meters,
            'max_accuracy_meters' => $location->max_accuracy_meters,
        ] : null;

        return $policy;
    }

    protected function casts(): array
    {
        return [
            'grace_minutes' => 'integer',
            'use_location_check' => 'boolean',
            'late_permission_proof_required' => 'boolean',
            'correction_proof_required' => 'boolean',
        ];
    }
}

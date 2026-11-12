<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'department_id', 'position_id', 'level_id', 'work_location_id', 'user_id', 'status', 'started_on', 'ended_on', 'phone', 'email', 'avatar_path', 'base_salary', 'religion', 'place_of_birth', 'date_of_birth', 'gender', 'marital_status', 'permanent_address', 'current_address'])]
#[Hidden(['base_salary', 'religion', 'place_of_birth', 'date_of_birth', 'gender', 'marital_status', 'permanent_address', 'current_address'])]
class Employee extends Model
{
    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return url('/private-media/avatars/employees/'.$this->id);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(EmployeeLevel::class, 'level_id');
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(EmployeePlacement::class)->orderByDesc('effective_from');
    }

    public function employmentTerms(): HasMany
    {
        return $this->hasMany(EmploymentTerm::class)->orderBy('starts_on');
    }

    public function levelAssignments(): HasMany
    {
        return $this->hasMany(EmployeeLevelAssignment::class)->orderBy('effective_from');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(DeviceRegistration::class, 'user_id', 'user_id');
    }

    public function effectiveLevelId(?string $date = null): ?int
    {
        if ($date) {
            $assignment = $this->levelAssignments()
                ->where('effective_from', '<=', $date)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
                ->orderByDesc('effective_from')
                ->first();
            if ($assignment) {
                return (int) $assignment->level_id;
            }
        }

        return $this->level_id ? (int) $this->level_id : null;
    }

    public function approvalTierFor(?string $date = null): string
    {
        $levelId = $this->effectiveLevelId($date);
        if (! $levelId) {
            return 'admin';
        }

        return EmployeeLevel::query()->whereKey($levelId)->value('requires_super_admin_approval')
            ? 'super_admin'
            : 'admin';
    }

    /** @return array<string, string> */
    public function attendanceGaps(?string $workDate = null): array
    {
        $gaps = [];
        if (! $this->department_id) {
            $gaps['department'] = 'departemen';
        }
        if (! $this->position_id) {
            $gaps['position'] = 'jabatan';
        }
        if (! $this->effectiveLevelId($workDate)) {
            $gaps['level'] = 'level tunjangan';
        }

        $this->loadMissing('workLocation');
        $location = $this->workLocation;
        if (! $this->work_location_id || ! $location) {
            $gaps['work_location'] = 'lokasi penempatan kerja';
        } elseif ($workDate) {
            $policy = AttendancePolicy::resolveFor($this, $workDate);
            if (($policy['use_location_check'] ?? false) && ! $this->locationReadyForGps()) {
                $gaps['work_location_gps'] = 'titik GPS lokasi penempatan';
            }
        }

        return $gaps;
    }

    public function attendanceBlockMessage(?string $workDate = null): ?string
    {
        $gaps = $this->attendanceGaps($workDate);

        return $gaps === [] ? null : self::formatAttendanceGaps($gaps);
    }

    public function assertReadyForAttendance(?string $workDate = null): void
    {
        $gaps = $this->attendanceGaps($workDate);
        if ($gaps === []) {
            return;
        }

        throw new BusinessRuleException(
            'EMPLOYEE_PROFILE_INCOMPLETE',
            self::formatAttendanceGaps($gaps),
            422,
            ['missing' => array_keys($gaps)],
        );
    }

    /** @param  array<string, string>  $gaps */
    public static function formatAttendanceGaps(array $gaps): string
    {
        $items = array_values($gaps);
        $list = match (count($items)) {
            1 => $items[0],
            2 => $items[0].' dan '.$items[1],
            default => implode(', ', array_slice($items, 0, -1)).', dan '.$items[array_key_last($items)],
        };

        return 'Absen belum bisa dicatat. Lengkapi '.$list.'. Hubungi HRD.';
    }

    private function locationReadyForGps(): bool
    {
        $location = $this->workLocation;

        return $location
            && $location->mode === 'radius'
            && $location->latitude !== null
            && $location->longitude !== null
            && $location->radius_meters;
    }

    protected function casts(): array
    {
        return [
            'base_salary' => 'integer',
            'date_of_birth' => 'date',
        ];
    }
}

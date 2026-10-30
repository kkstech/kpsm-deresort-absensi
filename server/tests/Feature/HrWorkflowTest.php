<?php

namespace Tests\Feature;

use App\Actions\PublishSchedule;
use App\Actions\RecordAttendance;
use App\Models\AllowancePolicy;
use App\Models\AllowanceResult;
use App\Models\Department;
use App\Models\DeviceRegistration;
use App\Models\Employee;
use App\Models\EmployeeLevel;
use App\Models\EmployeeLevelAssignment;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\ShiftTemplate;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-24 03:00:00');
        $this->admin = User::factory()->role('admin')->create();
        $this->actingAs($this->admin);
    }

    public function test_allowance_is_snapshotted_once_per_cycle_and_waits_for_verified_attendance(): void
    {
        $employee = $this->employee('ALW-1', '2026-01-01');
        $level = EmployeeLevel::query()->create(['code' => 'L2', 'name' => 'Level 2']);
        $employee->update(['level_id' => $level->id]);
        EmployeeLevelAssignment::query()->create(['employee_id' => $employee->id, 'level_id' => $level->id, 'effective_from' => '2026-01-01']);
        AllowancePolicy::query()->create(['level_id' => $level->id, 'effective_from' => '2026-01-01', 'meal_amount' => 7500, 'transport_amount' => 12000]);

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $schedules = app(PublishSchedule::class);
        $assignment = $schedules->upsert($employee, '2026-10-25', $template, $this->admin);
        $schedules->publish('2026-10-25', '2026-10-25', [$employee->id]);
        $sessionId = $assignment->sessions()->value('id');

        $this->travelTo('2026-10-25 01:10:00');
        $attendance = app(RecordAttendance::class);
        $attendance->handle($employee, $this->event($sessionId, '11111111-1111-4111-8111-111111111111', 'CHECK_IN'));
        $pending = AllowanceResult::query()->where('shift_assignment_id', $assignment->id)->firstOrFail();
        $this->assertSame('PENDING_VERIFICATION', $pending->status);
        $this->assertSame(0, $pending->meal_amount);

        $this->travelTo('2026-10-25 09:20:00');
        $attendance->handle($employee, $this->event($sessionId, '22222222-2222-4222-8222-222222222222', 'CHECK_OUT'));
        $eligible = $pending->fresh();
        $this->assertSame('ELIGIBLE', $eligible->status);
        $this->assertSame(480, $eligible->verified_minutes);
        $this->assertSame(7500, $eligible->meal_amount);
        $this->assertSame(12000, $eligible->transport_amount);

        AllowancePolicy::query()->create(['level_id' => $level->id, 'effective_from' => '2026-10-26', 'meal_amount' => 9000, 'transport_amount' => 15000]);
        $this->assertSame(7500, $eligible->fresh()->meal_amount);
        $this->assertSame(12000, $eligible->fresh()->transport_amount);
    }

    public function test_split_cycle_pays_one_allowance_when_only_one_session_is_attended(): void
    {
        $employee = $this->employee('ALW-SPLIT', '2026-01-01');
        $level = EmployeeLevel::query()->create(['code' => 'L-SPLIT', 'name' => 'Level Split']);
        $employee->update(['level_id' => $level->id]);
        EmployeeLevelAssignment::query()->create(['employee_id' => $employee->id, 'level_id' => $level->id, 'effective_from' => '2026-01-01']);
        AllowancePolicy::query()->create(['level_id' => $level->id, 'effective_from' => '2026-01-01', 'meal_amount' => 8000, 'transport_amount' => 14000]);
        $assignment = app(PublishSchedule::class)->upsertCustom($employee, '2026-10-25', [
            'name' => 'Split pagi', 'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-25', 'start_time' => '08:00', 'end_date' => '2026-10-25', 'end_time' => '12:00'],
                ['seq' => 2, 'start_date' => '2026-10-25', 'start_time' => '14:00', 'end_date' => '2026-10-25', 'end_time' => '18:00'],
            ],
        ], $this->admin);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$employee->id]);
        $sessionId = $assignment->sessions()->where('seq', 1)->value('id');

        $this->travelTo('2026-10-25 01:05:00');
        app(RecordAttendance::class)->handle($employee, $this->event($sessionId, '33333333-3333-4333-8333-333333333333', 'CHECK_IN'));
        $this->travelTo('2026-10-25 04:00:00');
        app(RecordAttendance::class)->handle($employee, $this->event($sessionId, '44444444-4444-4444-8444-444444444444', 'CHECK_OUT'));

        $result = AllowanceResult::query()->where('shift_assignment_id', $assignment->id)->firstOrFail();
        $this->assertSame('ELIGIBLE', $result->status);
        $this->assertSame(175, $result->verified_minutes);
        $this->assertSame(8000, $result->meal_amount);
        $this->assertSame(14000, $result->transport_amount);
        $this->assertSame(1, AllowanceResult::query()->where('shift_assignment_id', $assignment->id)->count());
    }

    public function test_leave_balance_prorates_first_year_reserves_pending_and_refunds_once(): void
    {
        $user = User::factory()->create();
        $employee = $this->employee('LEV-1', '2026-04-15', $user);
        $device = DeviceRegistration::query()->create(['user_id' => $user->id, 'device_uuid' => 'leave-phone']);
        $token = $user->createToken("device:{$device->id}")->plainTextToken;

        $this->postJson('/admin/api/leave-settings/confirm', ['reason' => 'Saldo awal diperiksa HRD'])->assertOk();
        $this->app['auth']->forgetGuards();
        $balance = $this->withToken($token)->getJson('/api/v1/me/leave-balance')->assertOk();
        $balance->assertJsonPath('data.regular.entitled', 9)->assertJsonPath('data.day_off.entitled', 3);

        $this->withToken($token)->postJson('/api/v1/leaves', [
            'work_date' => '2026-11-05', 'type' => 'regular', 'reason' => 'Keperluan keluarga',
        ])->assertCreated()->assertJsonPath('data.status', 'PENDING');
        $this->withToken($token)->getJson('/api/v1/me/leave-balance')
            ->assertOk()->assertJsonPath('data.regular.reserved', 1)->assertJsonPath('data.regular.available', 8);

        $requestId = LeaveRequest::query()->where('employee_id', $employee->id)->value('id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->postJson("/admin/api/leaves/{$requestId}/decision", [
            'decision' => 'APPROVED', 'decision_reason' => 'Disetujui',
        ])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me/leave-balance')
            ->assertOk()->assertJsonPath('data.regular.used', 1)->assertJsonPath('data.regular.reserved', 0);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->postJson("/admin/api/leaves/{$requestId}/cancel-approved", ['reason' => 'Jadwal berubah'])
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me/leave-balance')
            ->assertOk()->assertJsonPath('data.regular.used', 0)->assertJsonPath('data.regular.available', 9);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->postJson("/admin/api/leaves/{$requestId}/cancel-approved", ['reason' => 'Ulangi'])
            ->assertStatus(409);
    }

    public function test_hr_can_open_next_year_leave_balance_before_employee_requests_it(): void
    {
        $user = User::factory()->create();
        $this->employee('LEV-NEXT', '2026-01-01', $user);
        $device = DeviceRegistration::query()->create(['user_id' => $user->id, 'device_uuid' => 'leave-next-phone']);
        $token = $user->createToken("device:{$device->id}")->plainTextToken;

        $this->putJson('/admin/api/leave-settings', [
            'year' => 2027, 'regular_quota' => 12, 'day_off_quota' => 4, 'reason' => 'Tetapkan kuota tahun depan',
        ])->assertOk()->assertJsonPath('data.year', 2027);
        $opening = $this->postJson('/admin/api/leave-settings/confirm', ['year' => 2027, 'reason' => 'Saldo tahun depan diperiksa'])->assertOk();
        $this->assertNotNull($opening->json('data.opening_confirmed_at'));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/leaves', [
            'work_date' => '2027-01-10', 'type' => 'regular', 'reason' => 'Keperluan keluarga',
        ])->assertCreated()->assertJsonPath('data.status', 'PENDING');
    }

    public function test_contract_change_is_historical_and_expiry_does_not_deactivate_employee(): void
    {
        $this->postJson('/admin/api/employees', [
            'code' => 'CON-1', 'name' => 'Pegawai Kontrak', 'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'), 'status' => 'active', 'started_on' => '2026-01-10',
        ])->assertCreated();
        $employee = Employee::query()->where('code', 'CON-1')->firstOrFail();
        $this->postJson("/admin/api/employees/{$employee->id}/employment", [
            'type' => 'contract', 'starts_on' => '2026-10-25', 'ends_on' => '2026-11-01', 'reason' => 'Perpanjangan kontrak',
        ])->assertOk();

        $this->assertDatabaseHas('employment_terms', ['employee_id' => $employee->id, 'type' => 'unknown', 'ends_on' => '2026-10-24']);
        $this->assertDatabaseHas('employment_terms', ['employee_id' => $employee->id, 'type' => 'contract', 'ends_on' => '2026-11-01']);
        $this->getJson('/admin/api/employment-alerts?days=30')->assertOk()->assertJsonPath('data.0.status', 'soon');
        $this->assertSame('active', $employee->fresh()->status);
    }

    public function test_multi_day_leave_request_reserves_quota_detects_conflicts_and_approves_all_days(): void
    {
        $user = User::factory()->create();
        $employee = $this->employee('LEV-MULTI', '2026-01-01', $user);
        $device = DeviceRegistration::query()->create(['user_id' => $user->id, 'device_uuid' => 'multi-leave-phone']);
        $token = $user->createToken("device:{$device->id}")->plainTextToken;

        $this->postJson('/admin/api/leave-settings/confirm', ['reason' => 'Saldo awal diperiksa HRD'])->assertOk();
        $this->app['auth']->forgetGuards();

        // 1. Validation fails if end_date is before work_date
        $this->withToken($token)->postJson('/api/v1/leaves', [
            'work_date' => '2026-11-10',
            'end_date' => '2026-11-08',
            'type' => 'regular',
            'reason' => 'Libur terbalik',
        ])->assertStatus(422);

        // 2. Submit multi-day leave for 3 days: 2026-11-10 to 2026-11-12
        $res = $this->withToken($token)->postJson('/api/v1/leaves', [
            'work_date' => '2026-11-10',
            'end_date' => '2026-11-12',
            'type' => 'regular',
            'reason' => 'Libur 3 hari berturut-turut',
        ])->assertCreated();

        $res->assertJsonPath('data.work_date', '2026-11-10')
            ->assertJsonPath('data.end_date', '2026-11-12')
            ->assertJsonPath('data.days_count', 3)
            ->assertJsonPath('data.status', 'PENDING');

        // Balance should reserve 3 days
        $this->withToken($token)->getJson('/api/v1/me/leave-balance')
            ->assertOk()
            ->assertJsonPath('data.regular.reserved', 3)
            ->assertJsonPath('data.regular.available', 9); // 12 - 3 = 9

        // 3. Overlapping single day or multi-day leave is rejected
        $this->withToken($token)->postJson('/api/v1/leaves', [
            'work_date' => '2026-11-11',
            'type' => 'regular',
            'reason' => 'Tumpang tindih',
        ])->assertStatus(409)->assertJsonPath('code', 'LEAVE_ALREADY_REQUESTED');

        // 4. Admin approves the 3-day leave
        $requestId = $res->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->postJson("/admin/api/leaves/{$requestId}/decision", [
            'decision' => 'APPROVED',
            'decision_reason' => 'Disetujui 3 hari',
        ])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        // Balance reflects 3 used days
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me/leave-balance')
            ->assertOk()
            ->assertJsonPath('data.regular.used', 3)
            ->assertJsonPath('data.regular.reserved', 0)
            ->assertJsonPath('data.regular.available', 9);

        // 5. Admin direct multi-day leave for 2 days
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->postJson('/admin/api/leaves/direct', [
            'employee_id' => $employee->id,
            'work_date' => '2026-11-20',
            'end_date' => '2026-11-21',
            'type' => 'regular',
            'reason' => 'Cuti langsung HR 2 hari',
        ])->assertCreated()->assertJsonPath('data.days_count', 2)->assertJsonPath('data.status', 'APPROVED');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me/leave-balance')
            ->assertOk()
            ->assertJsonPath('data.regular.used', 5)
            ->assertJsonPath('data.regular.available', 7);
    }

    private function employee(string $code, string $startedOn, ?User $user = null): Employee
    {
        return Employee::query()->create($this->completeEmployeeAttrs([
            'code' => $code, 'name' => "Karyawan {$code}", 'status' => 'active', 'started_on' => $startedOn,
            'user_id' => $user?->id,
            'department_id' => Department::query()->value('id'), 'position_id' => Position::query()->value('id'),
        ]));
    }

    private function event(int $sessionId, string $uuid, string $type): array
    {
        return ['event_uuid' => $uuid, 'shift_session_id' => $sessionId, 'event_type' => $type, 'device_id' => 'hr-test-device', 'captured_offline' => false];
    }
}

<?php

namespace Tests\Feature;

use App\Actions\PublishSchedule;
use App\Models\AttendanceCorrection;
use App\Models\Department;
use App\Models\DeviceRegistration;
use App\Models\Employee;
use App\Models\EmployeeLevel;
use App\Models\LatePermission;
use App\Models\Position;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeLevelApprovalTierTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-24 03:00:00');
        $this->admin = User::factory()->role('admin')->create();
        $this->superAdmin = User::factory()->role('super_admin')->create();
    }

    public function test_level_flag_change_reroutes_pending_late_permission(): void
    {
        $user = User::factory()->create();
        $level = EmployeeLevel::query()->create([
            'code' => 'L2',
            'name' => 'Level 2',
            'requires_super_admin_approval' => false,
        ]);

        $employee = Employee::query()->create($this->completeEmployeeAttrs([
            'code' => 'X1',
            'name' => $user->name,
            'user_id' => $user->id,
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
            'level_id' => $level->id,
        ]));

        $device = DeviceRegistration::query()->create([
            'user_id' => $user->id,
            'device_uuid' => 'test-device',
            'name' => 'Test Device',
        ]);
        $token = $user->createToken("device:{$device->id}")->plainTextToken;

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $assignment = app(PublishSchedule::class)->upsert($employee, '2026-10-25', $template, $this->admin);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$employee->id]);
        $session = $assignment->sessions()->firstOrFail();

        $id = $this->withToken($token)->postJson('/api/v1/late-permissions', [
            'shift_session_id' => $session->id,
            'permission_type' => 'late_arrival',
            'allowed_minutes' => 30,
            'reason' => 'Macet parah',
        ])
            ->assertCreated()
            ->assertJsonPath('data.approval_tier', 'admin')
            ->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->getJson('/admin/api/workflows')
            ->assertJsonCount(1, 'late_permissions');

        $this->actingAs($this->superAdmin)->putJson("/admin/api/employee-levels/{$level->id}", [
            'code' => 'L2',
            'name' => 'Level 2',
            'requires_super_admin_approval' => true,
        ])->assertOk();

        $this->assertSame('super_admin', LatePermission::find($id)->approval_tier);

        $this->actingAs($this->admin)->getJson('/admin/api/workflows')
            ->assertJsonCount(0, 'late_permissions');

        $this->actingAs($this->superAdmin)->getJson('/admin/api/workflows')
            ->assertJsonCount(1, 'late_permissions');
    }

    public function test_level_flag_change_reroutes_pending_attendance_correction(): void
    {
        $user = User::factory()->create();
        $level = EmployeeLevel::query()->create([
            'code' => 'L3',
            'name' => 'Level 3',
            'requires_super_admin_approval' => false,
        ]);

        $employee = Employee::query()->create($this->completeEmployeeAttrs([
            'code' => 'X2',
            'name' => $user->name,
            'user_id' => $user->id,
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
            'level_id' => $level->id,
        ]));

        $device = DeviceRegistration::query()->create([
            'user_id' => $user->id,
            'device_uuid' => 'test-device-2',
            'name' => 'Test Device 2',
        ]);
        $token = $user->createToken("device:{$device->id}")->plainTextToken;

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $assignment = app(PublishSchedule::class)->upsert($employee, '2026-10-25', $template, $this->admin);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$employee->id]);
        $session = $assignment->sessions()->firstOrFail();

        $id = $this->withToken($token)->postJson('/api/v1/attendance-corrections', [
            'shift_session_id' => $session->id,
            'requested_check_in_at' => $session->start_at->addMinutes(10)->toIso8601String(),
            'requested_check_out_at' => $session->end_at->toIso8601String(),
            'reason' => 'Lupa absen',
        ])
            ->assertCreated()
            ->assertJsonPath('data.approval_tier', 'admin')
            ->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->getJson('/admin/api/workflows')
            ->assertJsonCount(1, 'corrections');

        $this->actingAs($this->superAdmin)->putJson("/admin/api/employee-levels/{$level->id}", [
            'code' => 'L3',
            'name' => 'Level 3',
            'requires_super_admin_approval' => true,
        ])->assertOk();

        $this->assertSame('super_admin', AttendanceCorrection::find($id)->approval_tier);

        $this->actingAs($this->admin)->getJson('/admin/api/workflows')
            ->assertJsonCount(0, 'corrections');

        $this->actingAs($this->superAdmin)->getJson('/admin/api/workflows')
            ->assertJsonCount(1, 'corrections');
    }

    public function test_level_assignment_with_immediate_effect_reroutes_pending_requests(): void
    {
        $user = User::factory()->create();
        $levelBasic = EmployeeLevel::query()->create([
            'code' => 'L1',
            'name' => 'Level 1',
            'requires_super_admin_approval' => false,
        ]);
        $levelSenior = EmployeeLevel::query()->create([
            'code' => 'L2',
            'name' => 'Level 2',
            'requires_super_admin_approval' => true,
        ]);

        $employee = Employee::query()->create($this->completeEmployeeAttrs([
            'code' => 'X3',
            'name' => $user->name,
            'user_id' => $user->id,
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
            'level_id' => $levelBasic->id,
        ]));

        $device = DeviceRegistration::query()->create([
            'user_id' => $user->id,
            'device_uuid' => 'test-device-3',
            'name' => 'Test Device 3',
        ]);
        $token = $user->createToken("device:{$device->id}")->plainTextToken;

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $assignment = app(PublishSchedule::class)->upsert($employee, '2026-10-25', $template, $this->admin);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$employee->id]);
        $session = $assignment->sessions()->firstOrFail();

        $latePermissionId = $this->withToken($token)->postJson('/api/v1/late-permissions', [
            'shift_session_id' => $session->id,
            'permission_type' => 'late_arrival',
            'allowed_minutes' => 45,
            'reason' => 'Kendaraan rusak',
        ])
            ->assertCreated()
            ->assertJsonPath('data.approval_tier', 'admin')
            ->json('data.id');

        $correctionId = $this->withToken($token)->postJson('/api/v1/attendance-corrections', [
            'shift_session_id' => $session->id,
            'requested_check_in_at' => $session->start_at->addMinutes(5)->toIso8601String(),
            'requested_check_out_at' => $session->end_at->toIso8601String(),
            'reason' => 'Lupa tap kartu',
        ])
            ->assertCreated()
            ->assertJsonPath('data.approval_tier', 'admin')
            ->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)
            ->postJson("/admin/api/employee-levels/{$employee->id}/assign", [
                'level_id' => $levelSenior->id,
                'effective_from' => '2026-10-24',
                'reason' => 'Promosi',
            ])
            ->assertOk();

        $this->assertSame('super_admin', LatePermission::find($latePermissionId)->approval_tier);
        $this->assertSame('super_admin', AttendanceCorrection::find($correctionId)->approval_tier);

        $this->actingAs($this->admin)->getJson('/admin/api/workflows')
            ->assertJsonCount(0, 'late_permissions')
            ->assertJsonCount(0, 'corrections');

        $this->actingAs($this->superAdmin)->getJson('/admin/api/workflows')
            ->assertJsonCount(1, 'late_permissions')
            ->assertJsonCount(1, 'corrections');
    }

    public function test_level_assignment_with_future_effective_date_does_not_affect_current_requests(): void
    {
        $user = User::factory()->create();
        $levelBasic = EmployeeLevel::query()->create([
            'code' => 'LB',
            'name' => 'Level Basic',
            'requires_super_admin_approval' => false,
        ]);
        $levelSenior = EmployeeLevel::query()->create([
            'code' => 'LS',
            'name' => 'Level Senior',
            'requires_super_admin_approval' => true,
        ]);

        $employee = Employee::query()->create($this->completeEmployeeAttrs([
            'code' => 'X4',
            'name' => $user->name,
            'user_id' => $user->id,
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
            'level_id' => $levelBasic->id,
        ]));

        $device = DeviceRegistration::query()->create([
            'user_id' => $user->id,
            'device_uuid' => 'test-device-4',
            'name' => 'Test Device 4',
        ]);
        $token = $user->createToken("device:{$device->id}")->plainTextToken;

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $assignment = app(PublishSchedule::class)->upsert($employee, '2026-10-25', $template, $this->admin);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$employee->id]);
        $session = $assignment->sessions()->firstOrFail();

        $latePermissionId = $this->withToken($token)->postJson('/api/v1/late-permissions', [
            'shift_session_id' => $session->id,
            'permission_type' => 'late_arrival',
            'allowed_minutes' => 20,
            'reason' => 'Antre di gerbang',
        ])
            ->assertCreated()
            ->assertJsonPath('data.approval_tier', 'admin')
            ->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)
            ->postJson("/admin/api/employee-levels/{$employee->id}/assign", [
                'level_id' => $levelSenior->id,
                'effective_from' => '2026-11-01',
                'reason' => 'Promosi tertunda',
            ])
            ->assertOk();

        $this->assertSame('admin', LatePermission::find($latePermissionId)->approval_tier);

        $this->actingAs($this->admin)->getJson('/admin/api/workflows')
            ->assertJsonCount(1, 'late_permissions');
    }

    public function test_admin_cannot_see_super_admin_tier_requests(): void
    {
        $user = User::factory()->create();
        $level = EmployeeLevel::query()->create([
            'code' => 'LS',
            'name' => 'Level Senior',
            'requires_super_admin_approval' => true,
        ]);

        $employee = Employee::query()->create($this->completeEmployeeAttrs([
            'code' => 'X5',
            'name' => $user->name,
            'user_id' => $user->id,
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
            'level_id' => $level->id,
        ]));

        $device = DeviceRegistration::query()->create([
            'user_id' => $user->id,
            'device_uuid' => 'test-device-5',
            'name' => 'Test Device 5',
        ]);
        $token = $user->createToken("device:{$device->id}")->plainTextToken;

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $assignment = app(PublishSchedule::class)->upsert($employee, '2026-10-25', $template, $this->admin);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$employee->id]);
        $session = $assignment->sessions()->firstOrFail();

        $this->withToken($token)->postJson('/api/v1/late-permissions', [
            'shift_session_id' => $session->id,
            'permission_type' => 'late_arrival',
            'allowed_minutes' => 30,
            'reason' => 'Test super admin tier',
        ])
            ->assertCreated()
            ->assertJsonPath('data.approval_tier', 'super_admin');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->getJson('/admin/api/workflows')
            ->assertJsonCount(0, 'late_permissions')
            ->assertJsonCount(0, 'corrections');

        $this->actingAs($this->superAdmin)->getJson('/admin/api/workflows')
            ->assertJsonCount(1, 'late_permissions');
    }

    public function test_super_admin_can_see_all_pending_requests(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $levelBasic = EmployeeLevel::query()->create([
            'code' => 'LB',
            'name' => 'Level Basic',
            'requires_super_admin_approval' => false,
        ]);
        $levelSenior = EmployeeLevel::query()->create([
            'code' => 'LS',
            'name' => 'Level Senior',
            'requires_super_admin_approval' => true,
        ]);

        $employee1 = Employee::query()->create($this->completeEmployeeAttrs([
            'code' => 'X6',
            'name' => $user1->name,
            'user_id' => $user1->id,
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
            'level_id' => $levelBasic->id,
        ]));

        $employee2 = Employee::query()->create($this->completeEmployeeAttrs([
            'code' => 'X7',
            'name' => $user2->name,
            'user_id' => $user2->id,
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
            'level_id' => $levelSenior->id,
        ]));

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $assignment1 = app(PublishSchedule::class)->upsert($employee1, '2026-10-25', $template, $this->admin);
        $assignment2 = app(PublishSchedule::class)->upsert($employee2, '2026-10-25', $template, $this->admin);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$employee1->id, $employee2->id]);

        $session1 = $assignment1->sessions()->firstOrFail();
        $session2 = $assignment2->sessions()->firstOrFail();

        $device1 = DeviceRegistration::query()->create([
            'user_id' => $user1->id,
            'device_uuid' => 'dev-1',
            'name' => 'Device 1',
        ]);
        $token1 = $user1->createToken("device:{$device1->id}")->plainTextToken;

        $device2 = DeviceRegistration::query()->create([
            'user_id' => $user2->id,
            'device_uuid' => 'dev-2',
            'name' => 'Device 2',
        ]);
        $token2 = $user2->createToken("device:{$device2->id}")->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($token1)->postJson('/api/v1/late-permissions', [
            'shift_session_id' => $session1->id,
            'permission_type' => 'late_arrival',
            'allowed_minutes' => 15,
            'reason' => 'Admin tier request',
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withToken($token2)->postJson('/api/v1/late-permissions', [
            'shift_session_id' => $session2->id,
            'permission_type' => 'late_arrival',
            'allowed_minutes' => 20,
            'reason' => 'Super admin tier request',
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->superAdmin)->getJson('/admin/api/workflows')
            ->assertJsonCount(2, 'late_permissions');
    }

    private function event(int $sessionId, string $uuid, string $type): array
    {
        return [
            'uuid' => $uuid,
            'shift_session_id' => $sessionId,
            'type' => $type,
            'effective_at' => CarbonImmutable::now()->toIso8601String(),
            'coordinates' => ['latitude' => -6.200000, 'longitude' => 106.816666],
        ];
    }
}

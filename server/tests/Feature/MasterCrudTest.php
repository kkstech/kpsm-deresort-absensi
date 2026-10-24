<?php

namespace Tests\Feature;

use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    public function test_guest_and_employee_get_json_errors(): void
    {
        $this->getJson('/admin/api/employees')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->actingAs(User::factory()->role('employee')->create())
            ->getJson('/admin/api/employees')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_admin_creates_employee_with_account_and_placement(): void
    {
        $this->asAdmin()->postJson('/admin/api/employees', $this->employeePayload([
            'account_email' => 'Rina@Deresort.test', 'account_password' => 'rahasia123',
        ]))->assertCreated();

        $employee = Employee::query()->where('code', 'FO-001')->firstOrFail();
        $this->assertSame('rina@deresort.test', $employee->user->email);
        $this->assertSame(1, $employee->placements()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'employees.created', 'subject_id' => $employee->id]);

        $this->postJson('/admin/api/employees', $this->employeePayload())
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonStructure(['details' => ['code']]);
    }

    public function test_department_change_records_placement_and_deactivate_revokes_access(): void
    {
        $this->asAdmin()->postJson('/admin/api/employees', $this->employeePayload([
            'account_email' => 'rina@deresort.test', 'account_password' => 'rahasia123',
        ]));
        $employee = Employee::query()->firstOrFail();
        $employee->user->createToken('device:1');

        $other = Department::query()->where('name', 'Finance')->value('id');
        $this->putJson("/admin/api/employees/{$employee->id}", $this->employeePayload(['department_id' => $other]))->assertOk();
        $this->assertSame(2, $employee->placements()->count());

        $this->postJson("/admin/api/employees/{$employee->id}/deactivate")->assertOk();
        $this->assertSame('inactive', $employee->fresh()->status);
        $this->assertFalse($employee->user->fresh()->is_active);
        $this->assertSame(0, $employee->user->tokens()->count());
    }

    public function test_supervisor_reads_only_own_department_and_cannot_write(): void
    {
        $fo = Department::query()->where('name', 'Front Office')->value('id');
        $finance = Department::query()->where('name', 'Finance')->value('id');
        $mine = $this->makeEmployee('A-1', $fo);
        $theirs = $this->makeEmployee('B-1', $finance);

        $supervisor = User::factory()->role('supervisor')->create(['department_id' => $fo]);
        $this->actingAs($supervisor)->getJson('/admin/api/employees')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'A-1');
        $this->getJson("/admin/api/employees/{$mine->id}")->assertOk();
        $this->getJson("/admin/api/employees/{$theirs->id}")->assertStatus(403);
        $this->postJson('/admin/api/departments', ['name' => 'Spa'])->assertStatus(403);
    }

    public function test_department_in_use_cannot_be_deleted(): void
    {
        $fo = Department::query()->where('name', 'Front Office')->value('id');
        $this->makeEmployee('A-1', $fo);

        $this->asAdmin()->deleteJson("/admin/api/departments/{$fo}")->assertStatus(409)->assertJsonPath('code', 'IN_USE');
        $this->postJson('/admin/api/departments', ['name' => 'Spa'])->assertCreated();
        $this->postJson('/admin/api/departments', ['name' => 'Spa'])->assertStatus(422);
    }

    public function test_template_sessions_are_validated(): void
    {
        $admin = $this->asAdmin();

        $admin->postJson('/admin/api/templates', ['name' => 'Malam 2', 'type' => 'regular', 'sessions' => [
            ['local_start' => '22:00', 'local_end' => '06:00'],
        ]])->assertCreated()->assertJsonPath('data.sessions.0.day_offset', 1);

        $admin->postJson('/admin/api/templates', ['name' => 'Bentrok', 'type' => 'special', 'sessions' => [
            ['local_start' => '06:00', 'local_end' => '10:00'], ['local_start' => '09:00', 'local_end' => '12:00'],
        ]])->assertStatus(422)->assertJsonStructure(['details' => ['sessions.1.local_start']]);

        $admin->postJson('/admin/api/templates', ['name' => 'Reguler 2', 'type' => 'regular', 'sessions' => [
            ['local_start' => '06:00', 'local_end' => '09:00'], ['local_start' => '12:00', 'local_end' => '15:00'],
        ]])->assertStatus(422);

        $admin->postJson('/admin/api/templates', ['name' => 'Lima', 'type' => 'special', 'sessions' => array_fill(0, 5, ['local_start' => '06:00', 'local_end' => '07:00'])])
            ->assertStatus(422);
    }

    public function test_policy_priority_individual_then_department_then_global(): void
    {
        $fo = Department::query()->where('name', 'Front Office')->value('id');
        $employee = $this->makeEmployee('A-1', $fo);
        $this->assertSame(0, AttendancePolicy::resolveFor($employee, '2026-11-01')['grace_minutes']);

        $this->asAdmin()->postJson('/admin/api/policies', ['scope_type' => 'department', 'department_id' => $fo, 'grace_minutes' => 10, 'use_location_check' => false, 'effective_from' => '2026-10-24'])->assertCreated();
        $this->assertSame(10, AttendancePolicy::resolveFor($employee, '2026-11-01')['grace_minutes']);

        $this->postJson('/admin/api/policies', ['scope_type' => 'employee', 'employee_id' => $employee->id, 'grace_minutes' => 5, 'use_location_check' => true, 'effective_from' => '2026-11-01'])->assertCreated();
        $this->assertSame(5, AttendancePolicy::resolveFor($employee, '2026-11-01')['grace_minutes']);
        $this->assertSame(10, AttendancePolicy::resolveFor($employee, '2026-10-31')['grace_minutes']);

        $this->postJson('/admin/api/policies', ['scope_type' => 'global', 'department_id' => $fo, 'grace_minutes' => 1, 'use_location_check' => false, 'effective_from' => '2026-10-24'])
            ->assertStatus(422);
    }

    public function test_super_admin_cannot_lock_self_out(): void
    {
        $boss = User::factory()->role('super_admin')->create();
        $this->actingAs($boss)
            ->putJson("/admin/api/users/{$boss->id}", ['name' => 'Boss', 'email' => $boss->email, 'role' => 'admin', 'can_view_all' => true, 'is_active' => true])
            ->assertStatus(409)->assertJsonPath('code', 'SELF_LOCKOUT');

        $this->actingAs(User::factory()->role('admin')->create())
            ->getJson('/admin/api/users')->assertStatus(403);
    }

    private function asAdmin(): static
    {
        return $this->actingAs(User::factory()->role('admin')->create());
    }

    private function employeePayload(array $extra = []): array
    {
        return [
            'code' => 'FO-001', 'name' => 'Rina Kartika', 'status' => 'active', 'started_on' => '2026-01-05',
            'department_id' => Department::query()->where('name', 'Front Office')->value('id'),
            'position_id' => Position::query()->value('id'),
            ...$extra,
        ];
    }

    private function makeEmployee(string $code, int $departmentId): Employee
    {
        return Employee::query()->create([
            'code' => $code, 'name' => "Karyawan {$code}", 'status' => 'active', 'started_on' => '2026-01-01',
            'department_id' => $departmentId, 'position_id' => Position::query()->value('id'),
        ]);
    }
}

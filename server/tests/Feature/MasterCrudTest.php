<?php

namespace Tests\Feature;

use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLevel;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_employee_age_includes_years_and_months(): void
    {
        $this->travelTo('2026-11-04 03:00:00');
        $this->asAdmin()->postJson('/admin/api/employees', $this->employeePayload([
            'code' => 'AGE-001',
            'date_of_birth' => '1990-11-12',
        ]))->assertCreated();

        $this->getJson('/admin/api/employees?code=AGE-001')->assertOk()
            ->assertJsonPath('data.0.current_age', 35)
            ->assertJsonPath('data.0.current_age_months', 11);
    }

    public function test_admin_creates_employee_with_weak_password_and_no_email(): void
    {
        $payload = $this->employeePayload([
            'code' => 'FO-999',
            'account_password' => '1234',
        ]);
        unset($payload['email']);

        $this->asAdmin()->postJson('/admin/api/employees', $payload)->assertCreated();
        $employee = Employee::query()->where('code', 'FO-999')->firstOrFail();
        $this->assertNotNull($employee->user_id);
        $this->assertSame('fo-999', $employee->user->username);
        $this->assertTrue(Hash::check('1234', $employee->user->password));
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

    public function test_admin_creates_and_updates_employee_with_level_id(): void
    {
        $level1 = EmployeeLevel::create(['code' => 'STF', 'name' => 'Staff']);
        $level2 = EmployeeLevel::create(['code' => 'SPV', 'name' => 'Supervisor']);

        $res = $this->asAdmin()->postJson('/admin/api/employees', $this->employeePayload([
            'code' => 'LVL-001',
            'level_id' => $level1->id,
        ]))->assertCreated();

        $employee = Employee::query()->where('code', 'LVL-001')->firstOrFail();
        $this->assertSame($level1->id, $employee->level_id);
        $this->assertSame(1, $employee->levelAssignments()->count());

        $this->putJson("/admin/api/employees/{$employee->id}", $this->employeePayload([
            'code' => 'LVL-001',
            'level_id' => $level2->id,
        ]))->assertOk();

        $this->assertSame($level2->id, $employee->fresh()->level_id);
        $this->assertSame(2, $employee->levelAssignments()->count());

        // Test filtering employees by level
        $filteredLvl2 = $this->asAdmin()->getJson('/admin/api/employees?level_id='.$level2->id)->assertOk();
        $this->assertTrue(collect($filteredLvl2->json('data'))->contains('id', $employee->id));

        $filteredLvl1 = $this->asAdmin()->getJson('/admin/api/employees?level_id='.$level1->id)->assertOk();
        $this->assertFalse(collect($filteredLvl1->json('data'))->contains('id', $employee->id));

        $filteredNone = $this->asAdmin()->getJson('/admin/api/employees?level_id=none')->assertOk();
        $this->assertFalse(collect($filteredNone->json('data'))->contains('id', $employee->id));

        // Test filtering employees by code
        $filteredCode = $this->asAdmin()->getJson('/admin/api/employees?code=LVL-001')->assertOk();
        $this->assertTrue(collect($filteredCode->json('data'))->contains('id', $employee->id));

        $filteredNonExistent = $this->asAdmin()->getJson('/admin/api/employees?code=NONEXISTENT')->assertOk();
        $this->assertFalse(collect($filteredNonExistent->json('data'))->contains('id', $employee->id));
    }

    public function test_admin_can_upload_and_update_employee_avatar(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('profile.jpg', 200, 200);

        $res = $this->asAdmin()->post('/admin/api/employees', [
            ...$this->employeePayload([
                'account_email' => 'avatar.test@deresort.test',
                'account_password' => 'password123',
            ]),
            'avatar' => $file,
        ], ['Accept' => 'application/json']);

        $res->assertCreated();
        $employee = Employee::query()->where('code', 'FO-001')->firstOrFail();
        $this->assertNotNull($employee->avatar_path);
        Storage::disk('public')->assertExists($employee->avatar_path);
        $this->assertSame($employee->avatar_path, $employee->user->fresh()->avatar_path);

        $listRes = $this->asAdmin()->getJson('/admin/api/employees');
        $listRes->assertOk();
        $this->assertNotNull($listRes->json('data.0.avatar_url'));

        // Update avatar with method spoofing
        $newFile = UploadedFile::fake()->image('profile2.png', 300, 300);
        $oldPath = $employee->avatar_path;

        $updateRes = $this->asAdmin()->post("/admin/api/employees/{$employee->id}", [
            ...$this->employeePayload(['name' => 'Rina Updated']),
            '_method' => 'PUT',
            'avatar' => $newFile,
        ], ['Accept' => 'application/json']);

        $updateRes->assertOk();
        $employee->refresh();
        $this->assertSame('Rina Updated', $employee->name);
        $this->assertNotSame($oldPath, $employee->avatar_path);
        Storage::disk('public')->assertExists($employee->avatar_path);
        Storage::disk('public')->assertMissing($oldPath);
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

    public function test_admin_user_can_be_linked_to_employee_and_keeps_admin_role(): void
    {
        $adminUser = User::factory()->role('admin')->create(['email' => 'hrd@deresort.test']);
        $deptId = Department::query()->first()->id;
        $posId = Position::query()->first()->id;

        // Create employee with the same email as existing admin
        $response = $this->actingAs($adminUser)->postJson('/admin/api/employees', [
            'code' => 'HR-001',
            'name' => 'HR Manager',
            'department_id' => $deptId,
            'position_id' => $posId,
            'status' => 'active',
            'started_on' => '2026-01-01',
            'email' => 'hrd@deresort.test',
            'account_email' => 'hrd@deresort.test',
        ])->assertCreated();

        $employeeId = $response->json('data.id');
        $employee = Employee::query()->findOrFail($employeeId);

        $this->assertSame($adminUser->id, $employee->user_id);
        $this->assertSame('admin', $adminUser->fresh()->role, 'Role admin tetap tidak berubah');
    }

    public function test_unlinked_users_and_employees_endpoints(): void
    {
        $admin = User::factory()->role('admin')->create(['name' => 'Admin Unlinked']);
        $fo = Department::query()->where('name', 'Front Office')->value('id');
        $employee = $this->makeEmployee('EMP-UNLINKED', $fo);

        $resUsers = $this->asAdmin()->getJson('/admin/api/unlinked-users')->assertOk();
        $userIds = collect($resUsers->json('data'))->pluck('id')->all();
        $this->assertContains($admin->id, $userIds);

        $resEmployees = $this->getJson('/admin/api/unlinked-employees')->assertOk();
        $empIds = collect($resEmployees->json('data'))->pluck('id')->all();
        $this->assertContains($employee->id, $empIds);
    }

    public function test_link_and_unlink_user_from_employee(): void
    {
        $supervisor = User::factory()->role('supervisor')->create(['name' => 'Supervisor Test', 'email' => 'spv.override@deresort.test']);
        $fo = Department::query()->where('name', 'Front Office')->value('id');
        $employee = $this->makeEmployee('EMP-LINK-1', $fo);
        $employee->update(['email' => 'old.employee.email@deresort.test']);

        $this->assertNull($employee->user_id);
        $this->assertSame('old.employee.email@deresort.test', $employee->email);

        $this->asAdmin()->postJson("/admin/api/employees/{$employee->id}/link-user", [
            'user_id' => $supervisor->id,
        ])->assertOk();

        $this->assertSame($supervisor->id, $employee->fresh()->user_id);
        $this->assertSame('spv.override@deresort.test', $employee->fresh()->email, 'Email karyawan otomatis tertimpa email user');
        $this->assertDatabaseHas('audit_logs', ['action' => 'employees.user_linked', 'subject_id' => $employee->id]);

        $this->postJson("/admin/api/employees/{$employee->id}/unlink-user")->assertOk();
        $this->assertNull($employee->fresh()->user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employees.user_unlinked', 'subject_id' => $employee->id]);
    }

    public function test_link_and_unlink_employee_from_user(): void
    {
        $superAdmin = User::factory()->role('super_admin')->create();
        $adminUser = User::factory()->role('admin')->create(['email' => 'admin.override@deresort.test']);
        $fo = Department::query()->where('name', 'Front Office')->value('id');
        $employee = $this->makeEmployee('EMP-LINK-2', $fo);
        $employee->update(['email' => 'old.emp2@deresort.test']);

        $this->actingAs($superAdmin)->postJson("/admin/api/users/{$adminUser->id}/link-employee", [
            'employee_id' => $employee->id,
        ])->assertOk();

        $this->assertSame($adminUser->id, $employee->fresh()->user_id);
        $this->assertSame('admin.override@deresort.test', $employee->fresh()->email, 'Email karyawan otomatis tertimpa email user');
        $this->assertSame($employee->id, $adminUser->fresh()->employee->id);

        $this->postJson("/admin/api/users/{$adminUser->id}/unlink-employee")->assertOk();
        $this->assertNull($employee->fresh()->user_id);
        $this->assertNull($adminUser->fresh()->employee);
    }

    public function test_create_employee_from_user(): void
    {
        $superAdmin = User::factory()->role('super_admin')->create();
        $adminUser = User::factory()->role('admin')->create(['name' => 'Budi Supervisor', 'email' => 'budi.spv@deresort.test']);
        $fo = Department::query()->where('name', 'Front Office')->value('id');
        $pos = Position::query()->value('id');

        $this->actingAs($superAdmin)->postJson("/admin/api/users/{$adminUser->id}/create-employee", [
            'code' => 'SPV-001',
            'department_id' => $fo,
            'position_id' => $pos,
            'started_on' => '2026-02-01',
        ])->assertCreated();

        $employee = Employee::query()->where('code', 'SPV-001')->firstOrFail();
        $this->assertSame($adminUser->id, $employee->user_id);
        $this->assertSame('Budi Supervisor', $employee->name);
        $this->assertSame('budi.spv@deresort.test', $employee->email);
        $this->assertSame(1, $employee->placements()->count());
        $this->assertSame(1, $employee->employmentTerms()->count());
    }

    public function test_deactivating_employee_preserves_web_admin_user(): void
    {
        $adminUser = User::factory()->role('admin')->create(['is_active' => true]);
        $fo = Department::query()->where('name', 'Front Office')->value('id');
        $employee = $this->makeEmployee('EMP-ADMIN-DEACT', $fo);
        $employee->update(['user_id' => $adminUser->id]);

        $this->asAdmin()->postJson("/admin/api/employees/{$employee->id}/deactivate")->assertOk();
        $this->assertSame('inactive', $employee->fresh()->status);
        $this->assertTrue($adminUser->fresh()->is_active, 'User admin web tidak boleh dinonaktifkan ketika profil karyawannya dinonaktifkan');
    }

    public function test_employee_devices_endpoint_and_revoking_all_devices(): void
    {
        $fo = Department::query()->where('name', 'Front Office')->value('id');
        $employee = $this->makeEmployee('EMP-DEV-1', $fo);
        $user = User::factory()->role('employee')->create(['email' => 'emp.dev@deresort.test']);
        $employee->update(['user_id' => $user->id]);

        $user->devices()->create(['device_uuid' => 'uuid-iphone', 'name' => 'iPhone 15 Pro', 'last_seen_at' => now()]);
        $user->devices()->create(['device_uuid' => 'uuid-ipad', 'name' => 'iPad Air', 'last_seen_at' => now()]);
        $user->createToken('device:1');
        $user->createToken('device:2');

        $res = $this->asAdmin()->getJson("/admin/api/employees/{$employee->id}/devices")->assertOk();
        $this->assertCount(2, $res->json('data'));
        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('iPhone 15 Pro', $names);
        $this->assertContains('iPad Air', $names);

        $this->postJson("/admin/api/employees/{$employee->id}/revoke-devices")->assertOk();
        $this->assertSame(0, $user->devices()->whereNull('revoked_at')->count());
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_admin_can_download_import_template(): void
    {
        $response = $this->asAdmin()->get('/admin/api/employees/import-template');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('template_import_karyawan.csv', (string) $response->headers->get('Content-Disposition'));
        $content = $response->getContent();
        $this->assertStringContainsString('kode_karyawan', $content);
        $this->assertStringContainsString('nama_lengkap', $content);
        $this->assertStringContainsString('FO-001', $content);
    }

    public function test_admin_can_preview_csv_and_get_validation_errors(): void
    {
        $csvContent = implode("\n", [
            'kode_karyawan,nama_lengkap,departemen,jabatan,tanggal_mulai_kerja,status_kerja,tanggal_selesai_kontrak,email,no_telepon,status,buat_akun,password_awal',
            'BULK-01,Budi Santoso,Front Office,Staff,2026-02-01,tetap,,budi@deresort.test,0811111111,aktif,ya,password123',
            'BULK-02,Siti Aminah,Housekeeping,Cleaner,tanggal-salah,kontrak,,siti@deresort.test,0822222222,aktif,tidak,password123',
        ]);

        $file = UploadedFile::fake()->createWithContent('karyawan.csv', $csvContent);

        $response = $this->asAdmin()->postJson('/admin/api/employees/import-preview', [
            'file' => $file,
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('total_rows'));
        $this->assertSame(1, $response->json('valid_count'));
        $this->assertSame(1, $response->json('error_count'));
        $rows = $response->json('rows');
        $this->assertTrue($rows[0]['_valid']);
        $this->assertFalse($rows[1]['_valid']);
        $this->assertNotEmpty($rows[1]['_errors']);
    }

    public function test_admin_can_bulk_import_employees_creating_departments_positions_terms_and_accounts(): void
    {
        $payload = [
            'rows' => [
                [
                    'code' => 'IMP-001',
                    'name' => 'Imported User One',
                    'department' => 'Food & Beverage',
                    'position' => 'Waiter',
                    'started_on' => '2026-03-01',
                    'employment_type' => 'permanent',
                    'ends_on' => null,
                    'email' => 'imp1@deresort.test',
                    'phone' => '0833333333',
                    'level' => null,
                    'work_location' => null,
                    'status' => 'active',
                    'make_account' => true,
                    'password' => 'secret123',
                ],
                [
                    'code' => 'IMP-002',
                    'name' => 'Imported User Two',
                    'department' => 'Engineering',
                    'position' => 'Technician',
                    'started_on' => '2026-03-15',
                    'employment_type' => 'contract',
                    'ends_on' => '2026-12-31',
                    'email' => 'imp2@deresort.test',
                    'phone' => '0844444444',
                    'level' => null,
                    'work_location' => null,
                    'status' => 'active',
                    'make_account' => false,
                    'password' => null,
                ],
            ],
        ];

        $response = $this->asAdmin()->postJson('/admin/api/employees/import', $payload);
        $response->assertOk();
        $this->assertSame(2, $response->json('imported_count'));

        $emp1 = Employee::query()->where('code', 'IMP-001')->firstOrFail();
        $this->assertSame('Imported User One', $emp1->name);
        $this->assertSame('Food & Beverage', $emp1->department->name);
        $this->assertSame('Waiter', $emp1->position->name);
        $this->assertNotNull($emp1->user_id);
        $this->assertSame('imp1@deresort.test', $emp1->user->email);
        $this->assertSame(1, $emp1->placements()->count());
        $this->assertSame('permanent', $emp1->employmentTerms()->first()->type);

        $emp2 = Employee::query()->where('code', 'IMP-002')->firstOrFail();
        $this->assertSame('Imported User Two', $emp2->name);
        $this->assertNull($emp2->user_id);
        $this->assertSame('contract', $emp2->employmentTerms()->first()->type);
        $this->assertSame('2026-12-31', $emp2->employmentTerms()->first()->ends_on->format('Y-m-d'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employees.bulk_imported',
        ]);
    }

    public function test_admin_can_manage_employee_levels_via_both_endpoints(): void
    {
        $res1 = $this->asAdmin()->postJson('/admin/api/employee-levels', [
            'code' => 'STF', 'name' => 'Staff Regular',
        ]);
        $res1->assertCreated();
        $this->assertSame('STF', $res1->json('data.code'));
        $this->assertSame('Staff Regular', $res1->json('data.name'));
        $this->assertFalse($res1->json('data.requires_super_admin_approval'));

        $res2 = $this->asAdmin()->postJson('/admin/api/levels', [
            'code' => 'SPV', 'name' => 'Supervisor Level',
        ]);
        $res2->assertCreated();
        $this->assertSame('SPV', $res2->json('data.code'));
        $this->assertFalse($res2->json('data.requires_super_admin_approval'));

        $this->asAdmin()->getJson('/admin/api/employee-levels')->assertOk()->assertJsonCount(2, 'data');
        $this->asAdmin()->getJson('/admin/api/levels')->assertOk()->assertJsonCount(2, 'data');

        $levelId = $res1->json('data.id');
        $this->asAdmin()->putJson("/admin/api/employee-levels/{$levelId}", [
            'code' => 'STF', 'name' => 'Staff Senior', 'requires_super_admin_approval' => true,
        ])->assertOk()->assertJsonPath('data.name', 'Staff Senior')->assertJsonPath('data.requires_super_admin_approval', true);

        $this->asAdmin()->putJson("/admin/api/employee-levels/{$levelId}", [
            'code' => 'STF', 'name' => 'Staff Senior',
        ])->assertOk()->assertJsonPath('data.requires_super_admin_approval', true);

        $this->asAdmin()->deleteJson("/admin/api/levels/{$levelId}")->assertOk();
        $this->asAdmin()->getJson('/admin/api/levels')->assertOk()->assertJsonCount(1, 'data');
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

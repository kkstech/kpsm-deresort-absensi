<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DeviceRegistration;
use App\Models\Employee;
use App\Models\Position;
use App\Models\ShiftAssignment;
use App\Models\ShiftExchangeRequest;
use App\Models\ShiftTemplate;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftExchangeTest extends TestCase
{
    use RefreshDatabase;

    private Employee $emp1;
    private Employee $emp2;
    private User $user1;
    private User $user2;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-24 03:00:00'); // 10.00 WIB

        $dept = Department::query()->first();
        $pos = Position::query()->first();

        $this->emp1 = Employee::query()->create([
            'code' => 'FO-01',
            'name' => 'Alice Foster',
            'status' => 'active',
            'started_on' => '2026-01-01',
            'department_id' => $dept->id,
            'position_id' => $pos->id,
        ]);
        $this->user1 = User::factory()->role('employee')->create([
            'name' => 'Alice Foster',
            'email' => 'alice@deresort.test',
        ]);
        $this->emp1->update(['user_id' => $this->user1->id]);

        $this->emp2 = Employee::query()->create([
            'code' => 'FO-02',
            'name' => 'Bob Davis',
            'status' => 'active',
            'started_on' => '2026-01-01',
            'department_id' => $dept->id,
            'position_id' => $pos->id,
        ]);
        $this->user2 = User::factory()->role('employee')->create([
            'name' => 'Bob Davis',
            'email' => 'bob@deresort.test',
        ]);
        $this->emp2->update(['user_id' => $this->user2->id]);

        // Pure super admin without an employee record
        $this->adminUser = User::factory()->role('super_admin')->create([
            'name' => 'HR Director',
            'email' => 'hr@deresort.test',
        ]);
    }

    public function test_admin_without_employee_can_login_to_mobile_api(): void
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'hr@deresort.test',
            'password' => 'password',
            'device_uuid' => 'hr-phone-uuid',
            'device_name' => 'Admin iPhone',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'role', 'employee']]);
        $this->assertNull($res->json('user.employee'));

        $token = $res->json('token');

        // Can access /me
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.role', 'super_admin');

        // Normal employee endpoints return empty/null gracefully
        $this->withToken($token)->getJson('/api/v1/attendance/me?from=2026-10-24&to=2026-11-24')->assertOk()->assertExactJson(['data' => []]);
        $this->withToken($token)->getJson('/api/v1/me/leave-balance')->assertOk()->assertExactJson(['data' => null]);
        $this->withToken($token)->getJson('/api/v1/leaves')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_employee_can_view_colleagues_and_schedules(): void
    {
        $token1 = $this->mobileLogin($this->user1, 'phone-1');

        $ass2 = $this->createAssignment($this->emp2, '2026-10-27', 'Reguler');

        // Colleagues list
        $colleaguesRes = $this->withToken($token1)->getJson('/api/v1/shift-exchanges/colleagues');
        $colleaguesRes->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($this->emp2->id, $colleaguesRes->json('data.0.id'));

        // Employee schedules
        $schedulesRes = $this->withToken($token1)->getJson("/api/v1/shift-exchanges/employee-schedules?employee_id={$this->emp2->id}");
        $schedulesRes->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($ass2->id, $schedulesRes->json('data.0.id'));
    }

    public function test_employee_can_submit_and_cancel_direct_shift_exchange(): void
    {
        $token1 = $this->mobileLogin($this->user1, 'phone-1');

        $ass1 = $this->createAssignment($this->emp1, '2026-10-26', 'Reguler');
        $ass2 = $this->createAssignment($this->emp2, '2026-10-27', 'Reguler');

        // Submit direct shift exchange
        $res = $this->withToken($token1)->postJson('/api/v1/shift-exchanges', [
            'shift_assignment_id' => $ass1->id,
            'target_employee_id' => $this->emp2->id,
            'swap_mode' => 'direct',
            'target_assignment_id' => $ass2->id,
            'reason' => 'Ada keperluan keluarga mendesak hari Senin',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.swap_mode', 'direct')
            ->assertJsonPath('data.is_requester', true)
            ->assertJsonPath('data.employee.name', 'Alice Foster')
            ->assertJsonPath('data.target_employee.name', 'Bob Davis');

        $requestId = $res->json('data.id');

        // Read my shift exchanges
        $this->withToken($token1)->getJson('/api/v1/shift-exchanges')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestId);

        // Cancel the request
        $this->withToken($token1)->postJson("/api/v1/shift-exchanges/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertSame('CANCELLED', ShiftExchangeRequest::query()->find($requestId)->status);
    }

    public function test_admin_can_reject_shift_exchange(): void
    {
        $token1 = $this->mobileLogin($this->user1, 'phone-1');
        $ass1 = $this->createAssignment($this->emp1, '2026-10-26', 'Reguler');
        $ass2 = $this->createAssignment($this->emp2, '2026-10-27', 'Reguler');

        $createRes = $this->withToken($token1)->postJson('/api/v1/shift-exchanges', [
            'shift_assignment_id' => $ass1->id,
            'target_employee_id' => $this->emp2->id,
            'swap_mode' => 'direct',
            'target_assignment_id' => $ass2->id,
            'reason' => 'Tukar shift',
        ]);
        $requestId = $createRes->json('data.id');

        $this->app['auth']->forgetGuards();
        $adminToken = $this->mobileLogin($this->adminUser, 'hr-phone');

        // Admin lists requests
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->getJson('/api/v1/shift-exchanges?scope=admin&status=PENDING')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Admin rejects
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->postJson("/api/v1/shift-exchanges/{$requestId}/decide", [
            'decision' => 'REJECTED',
            'reason' => 'Shift tanggal tersebut membutuhkan keahlian Alice di front desk.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.decision_reason', 'Shift tanggal tersebut membutuhkan keahlian Alice di front desk.');

        $this->assertSame('REJECTED', ShiftExchangeRequest::query()->find($requestId)->status);
    }

    public function test_admin_can_approve_direct_shift_exchange_and_swap_is_executed(): void
    {
        $token1 = $this->mobileLogin($this->user1, 'phone-1');
        $ass1 = $this->createAssignment($this->emp1, '2026-10-26', 'Reguler');
        $ass2 = $this->createAssignment($this->emp2, '2026-10-27', 'Reguler');

        $createRes = $this->withToken($token1)->postJson('/api/v1/shift-exchanges', [
            'shift_assignment_id' => $ass1->id,
            'target_employee_id' => $this->emp2->id,
            'swap_mode' => 'direct',
            'target_assignment_id' => $ass2->id,
            'reason' => 'Tukar shift jadwal minggu ini',
        ]);
        $requestId = $createRes->json('data.id');

        $this->app['auth']->forgetGuards();
        $adminToken = $this->mobileLogin($this->adminUser, 'hr-phone');

        // Admin approves
        $this->app['auth']->forgetGuards();
        $decideRes = $this->withToken($adminToken)->postJson("/api/v1/shift-exchanges/{$requestId}/decide", [
            'decision' => 'APPROVED',
            'reason' => 'Disetujui oleh HRD',
        ]);

        $decideRes->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        // Verify that assignments were actually swapped!
        $freshAss1 = ShiftAssignment::query()->find($ass1->id);
        $freshAss2 = ShiftAssignment::query()->find($ass2->id);

        $this->assertSame($this->emp2->id, $freshAss1->employee_id, 'ass1 is now given to emp2');
        $this->assertSame($this->emp1->id, $freshAss2->employee_id, 'ass2 is now given to emp1');
    }

    public function test_admin_can_approve_delegation_shift_exchange(): void
    {
        $token1 = $this->mobileLogin($this->user1, 'phone-1');
        $ass1 = $this->createAssignment($this->emp1, '2026-10-26', 'Reguler');

        $createRes = $this->withToken($token1)->postJson('/api/v1/shift-exchanges', [
            'shift_assignment_id' => $ass1->id,
            'target_employee_id' => $this->emp2->id,
            'swap_mode' => 'delegation',
            'vacancy_action' => 'roster_off',
            'reason' => 'Pelimpahan shift ke Bob',
        ]);
        $requestId = $createRes->json('data.id');

        $this->app['auth']->forgetGuards();
        $adminToken = $this->mobileLogin($this->adminUser, 'hr-phone');

        // Admin approves delegation
        $this->app['auth']->forgetGuards();
        $decideRes = $this->withToken($adminToken)->postJson("/api/v1/shift-exchanges/{$requestId}/decide", [
            'decision' => 'APPROVED',
            'vacancy_action' => 'roster_off',
            'reason' => 'Pelimpahan shift disetujui',
        ]);

        $decideRes->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $freshAss1 = ShiftAssignment::query()->find($ass1->id);
        $this->assertSame($this->emp2->id, $freshAss1->employee_id, 'ass1 is now transferred to emp2');
    }

    public function test_admin_can_preview_and_commit_direct_swap_via_mobile_api(): void
    {
        $adminToken = $this->mobileLogin($this->adminUser, 'hr-phone');

        $ass1 = $this->createAssignment($this->emp1, '2026-10-26', 'Reguler');
        $ass2 = $this->createAssignment($this->emp2, '2026-10-26', 'Malam');

        $moves = [
            ['assignment_id' => $ass1->id, 'expected_revision' => $ass1->revision, 'to_employee_id' => $this->emp2->id],
            ['assignment_id' => $ass2->id, 'expected_revision' => $ass2->revision, 'to_employee_id' => $this->emp1->id],
        ];

        // Preview swap
        $this->app['auth']->forgetGuards();
        $previewRes = $this->withToken($adminToken)->postJson('/api/v1/admin/schedule/swap/preview', [
            'moves' => $moves,
        ]);

        $previewRes->assertOk()
            ->assertJsonPath('can_commit', true)
            ->assertJsonStructure(['preview_token', 'moves', 'vacancies', 'blockers']);

        $token = $previewRes->json('preview_token');

        // Commit swap
        $this->app['auth']->forgetGuards();
        $commitRes = $this->withToken($adminToken)->postJson('/api/v1/admin/schedule/swap', [
            'moves' => $moves,
            'preview_token' => $token,
            'reason' => 'Pengaturan langsung dari aplikasi mobile HRD',
        ]);

        $commitRes->assertOk()->assertJsonStructure(['swap_id', 'data']);

        $this->assertSame($this->emp2->id, ShiftAssignment::query()->find($ass1->id)->employee_id);
        $this->assertSame($this->emp1->id, ShiftAssignment::query()->find($ass2->id)->employee_id);
    }

    private function mobileLogin(User $user, string $deviceUuid): string
    {
        $this->app['auth']->forgetGuards();
        DeviceRegistration::query()->create([
            'user_id' => $user->id,
            'device_uuid' => $deviceUuid,
            'name' => 'Test Device',
            'last_seen_at' => now(),
        ]);

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => $deviceUuid,
            'device_name' => 'Test Phone',
        ])->assertOk()->json('token');
    }

    private function createAssignment(Employee $employee, string $date, string $templateName): ShiftAssignment
    {
        $template = ShiftTemplate::query()->where('name', $templateName)->firstOrFail();
        $ass = ShiftAssignment::query()->create([
            'employee_id' => $employee->id,
            'work_date' => $date,
            'shift_template_id' => $template->id,
            'status' => 'PUBLISHED',
            'published_at' => now(),
            'template_name' => $template->name,
            'template_type' => $template->type,
            'rules_version' => 1,
            'revision' => 1,
            'scheduled_minutes' => 480,
        ]);

        foreach ($template->sessions as $s) {
            $ass->sessions()->create([
                'seq' => $s->seq,
                'schedule_revision' => 1,
                'start_at' => "{$date}T01:00:00Z", // 08:00 WIB
                'end_at' => "{$date}T10:00:00Z",   // 17:00 WIB
                'unpaid_break_minutes' => $s->unpaid_break_minutes,
                'policy_snapshot' => [
                    'grace_minutes' => 0,
                    'use_location_check' => false,
                ],
            ]);
        }

        return $ass;
    }
}

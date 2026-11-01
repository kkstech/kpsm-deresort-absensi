<?php

namespace Tests\Feature;

use App\Actions\PublishSchedule;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DeviceRegistration;
use App\Models\Employee;
use App\Models\OvertimeOrder;
use App\Models\Position;
use App\Models\ShiftSession;
use App\Models\ShiftTemplate;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OvertimeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employeeUser;

    private Employee $employee;

    private DeviceRegistration $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-25 03:00:00'); // 10.00 WIB

        $this->admin = User::factory()->role('admin')->create();
        $this->employeeUser = User::factory()->create();
        $this->employee = Employee::query()->create($this->completeEmployeeAttrs([
            'code' => 'FO-001',
            'name' => 'Budi Santoso',
            'status' => 'active',
            'started_on' => '2026-01-01',
            'user_id' => $this->employeeUser->id,
            'department_id' => Department::query()->first()->id,
            'position_id' => Position::query()->first()->id,
        ]));

        $this->device = DeviceRegistration::query()->create([
            'user_id' => $this->employeeUser->id,
            'device_uuid' => (string) Str::uuid(),
            'device_name' => 'Test Mobile Device',
            'status' => 'active',
        ]);
        $this->token = $this->employeeUser->createToken("device:{$this->device->id}")->plainTextToken;
    }

    public function test_admin_can_issue_overtime_order_and_employee_can_give_digital_consent(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/admin/api/overtimes/order', [
            'employee_ids' => [$this->employee->id],
            'work_date' => '2026-10-26',
            'start_time' => '17:00',
            'end_time' => '20:00',
            'title' => 'Lembur Event Wedding',
        ])->assertCreated();

        $orderId = $response->json('data.0.id');
        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'employee_id' => $this->employee->id,
            'title' => 'Lembur Event Wedding',
            'status' => 'APPROVED',
            'consent_status' => 'PENDING',
            'planned_minutes' => 180,
        ]);

        // Clear web admin session before mobile API call
        auth()->forgetGuards();
        $this->flushSession();

        // Karyawan melihat perintah lembur di mobile dan memberikan consent
        $list = $this->withToken($this->token)->getJson('/api/v1/me/overtimes')->assertOk();
        $list->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Lembur Event Wedding')
            ->assertJsonPath('data.0.consent_status', 'PENDING');

        $consentResponse = $this->withToken($this->token)->postJson("/api/v1/overtimes/{$orderId}/consent", [
            'accepted' => true,
            'notes' => 'Siap bertugas',
        ])->assertOk();

        $consentResponse->assertJsonPath('data.consent_status', 'ACCEPTED');
        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'consent_status' => 'ACCEPTED',
            'consent_notes' => 'Siap bertugas',
        ]);
    }

    public function test_employee_can_submit_overtime_request_and_admin_approves(): void
    {
        $assignment = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-26', [
            'name' => 'Split 12 Jam',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '08:00', 'end_date' => '2026-10-26', 'end_time' => '14:00'],
                ['seq' => 2, 'start_date' => '2026-10-26', 'start_time' => '15:00', 'end_date' => '2026-10-26', 'end_time' => '21:00'],
            ],
        ], $this->admin);

        foreach ($assignment->sessions as $session) {
            AttendanceRecord::query()->create([
                'shift_session_id' => $session->id,
                'employee_id' => $this->employee->id,
                'check_in_at' => $session->start_at,
                'check_out_at' => $session->end_at,
                'status' => 'VERIFIED',
                'verified_minutes' => $session->scheduledMinutes(),
            ]);
        }

        $req = $this->withToken($this->token)->postJson('/api/v1/overtimes', [
            'work_date' => '2026-10-26',
            'start_time' => '21:00',
            'end_time' => '23:00',
            'title' => 'Pembersihan Banquet Tambahan',
        ])->assertCreated();

        $orderId = $req->json('data.id');
        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'employee_id' => $this->employee->id,
            'status' => 'PENDING',
            'consent_status' => 'ACCEPTED',
            'planned_minutes' => 120,
        ]);

        // Admin memeriksa dan menyetujui
        $this->actingAs($this->admin);
        $adminList = $this->getJson('/admin/api/overtimes?status=PENDING')->assertOk();
        $adminList->assertJsonCount(1, 'data');

        $this->postJson("/admin/api/overtimes/{$orderId}/decision", [
            'decision' => 'APPROVED',
            'notes' => 'Disetujui untuk banquet',
        ])->assertOk();

        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'status' => 'APPROVED',
            'decision_notes' => 'Disetujui untuk banquet',
        ]);
    }

    public function test_checkout_after_session_end_calculates_verified_overtime_minutes_capped_by_spl(): void
    {
        $template = ShiftTemplate::query()->where('name', 'Reguler')->firstOrFail();

        // 1. Buat jadwal 2026-10-26 (08:00 - 17:00 = 01:00 - 10:00 UTC)
        $this->actingAs($this->admin);
        $this->postJson('/admin/api/schedule', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-26',
            'shift_template_id' => $template->id,
        ])->assertCreated();
        $this->postJson('/admin/api/schedule/publish', ['from' => '2026-10-26', 'to' => '2026-10-26'])->assertOk();

        // 2. Buat SPL lembur (17:00 - 19:00 WIB = 120 menit planned)
        $orderRes = $this->postJson('/admin/api/overtimes/order', [
            'employee_ids' => [$this->employee->id],
            'work_date' => '2026-10-26',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'title' => 'Lembur Regular Closing',
        ])->assertCreated();
        $orderId = $orderRes->json('data.0.id');

        // Consent diterima
        OvertimeOrder::query()->where('id', $orderId)->update(['consent_status' => 'ACCEPTED', 'consent_at' => now()]);

        auth()->forgetGuards();
        $this->flushSession();

        // 3. Karyawan Check-in jam 08:00 WIB (01:00 UTC)
        $session = ShiftSession::query()->whereHas('assignment', fn ($q) => $q->where('employee_id', $this->employee->id))->firstOrFail();

        $this->travelTo('2026-10-26 01:00:00');
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'shift_session_id' => $session->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'CHECK_IN',
            'device_id' => $this->device->device_uuid,
            'client_recorded_at' => '2026-10-26T08:00:00+07:00',
        ])->assertCreated();

        // 4. Karyawan Check-out jam 18:30 WIB (11:30 UTC = 90 menit setelah 17:00 WIB / 10:00 UTC)
        $this->travelTo('2026-10-26 11:30:00');
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'shift_session_id' => $session->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'CHECK_OUT',
            'device_id' => $this->device->device_uuid,
            'client_recorded_at' => '2026-10-26T18:30:00+07:00',
        ])->assertCreated();

        // 5. Verifikasi bahwa 90 menit lembur tercatat
        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'actual_minutes' => 90,
            'verified_minutes' => 90,
        ]);

        $this->assertDatabaseHas('attendance_records', [
            'shift_session_id' => $session->id,
            'overtime_order_id' => $orderId,
            'verified_overtime_minutes' => 90,
        ]);
    }

    public function test_day_off_overtime_order_allows_checkin_and_checkout(): void
    {
        $this->actingAs($this->admin);

        // Karyawan tidak punya jadwal di 2026-10-27 (Day Off)
        $orderRes = $this->postJson('/admin/api/overtimes/order', [
            'employee_ids' => [$this->employee->id],
            'work_date' => '2026-10-27',
            'start_time' => '09:00',
            'end_time' => '13:00',
            'title' => 'Lembur Darurat Maintenance',
        ])->assertCreated();
        $orderId = $orderRes->json('data.0.id');

        OvertimeOrder::query()->where('id', $orderId)->update(['consent_status' => 'ACCEPTED', 'consent_at' => now()]);

        // Pastikan shift hari libur otomatis terbuat
        $this->assertDatabaseHas('shift_assignments', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-27',
            'template_name' => 'Lembur Hari Libur',
            'status' => 'PUBLISHED',
        ]);

        $session = ShiftSession::query()->whereHas('assignment', fn ($q) => $q->where('employee_id', $this->employee->id)->where('work_date', '2026-10-27'))->firstOrFail();

        auth()->forgetGuards();
        $this->flushSession();

        // Check-in & Check-out oleh karyawan
        $this->travelTo('2026-10-27 02:00:00'); // 09:00 WIB
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'shift_session_id' => $session->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'CHECK_IN',
            'device_id' => $this->device->device_uuid,
            'client_recorded_at' => '2026-10-27T09:00:00+07:00',
        ])->assertCreated();

        $this->travelTo('2026-10-27 06:00:00'); // 13:00 WIB (4 jam = 240 menit)
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'shift_session_id' => $session->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'CHECK_OUT',
            'device_id' => $this->device->device_uuid,
            'client_recorded_at' => '2026-10-27T13:00:00+07:00',
        ])->assertCreated();

        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'actual_minutes' => 240,
            'verified_minutes' => 240,
        ]);
    }
}

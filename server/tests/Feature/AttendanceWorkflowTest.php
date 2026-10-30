<?php

namespace Tests\Feature;

use App\Actions\ManageAttendanceWorkflow;
use App\Actions\PublishSchedule;
use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceAttachment;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\AttendancePeriod;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\DeviceRegistration;
use App\Models\Employee;
use App\Models\LatePermission;
use App\Models\Position;
use App\Models\ShiftSession;
use App\Models\ShiftTemplate;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $employeeUser;

    private Employee $employee;

    private string $token;

    private ShiftSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-24 03:00:00');

        $this->employeeUser = User::factory()->create();
        $this->employee = Employee::query()->create([
            'code' => 'WF-001',
            'name' => $this->employeeUser->name,
            'user_id' => $this->employeeUser->id,
            'department_id' => Department::query()->where('name', 'Engineering')->value('id'),
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
        ]);
        $device = DeviceRegistration::query()->create([
            'user_id' => $this->employeeUser->id,
            'device_uuid' => 'workflow-phone',
            'name' => 'Test phone',
        ]);
        $this->token = $this->employeeUser->createToken("device:{$device->id}")->plainTextToken;

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $creator = User::factory()->role('admin')->create();
        $assignment = app(PublishSchedule::class)->upsert($this->employee, '2026-10-25', $template, $creator);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$this->employee->id]);
        $this->session = $assignment->sessions()->firstOrFail();
    }

    public function test_late_permission_requires_configured_evidence_and_supervisor_approval_preserves_raw_time(): void
    {
        Storage::fake('local');
        $this->session->update(['policy_snapshot' => [...$this->session->policy_snapshot, 'late_permission_proof_required' => true]]);
        $input = [
            'shift_session_id' => $this->session->id,
            'allowed_minutes' => 60,
            'reason' => 'Kendala transportasi',
        ];

        $this->withToken($this->token)->postJson('/api/v1/late-permissions', $input)
            ->assertUnprocessable()->assertJsonPath('code', 'EVIDENCE_REQUIRED');

        $response = $this->withToken($this->token)->post('/api/v1/late-permissions', [
            ...$input,
            'evidence' => UploadedFile::fake()->create('surat.pdf', 24, 'application/pdf'),
        ]);
        $response->assertCreated()->assertJsonPath('data.status', 'PENDING')->assertJsonMissingPath('data.attachments.0.path');
        $permissionId = $response->json('data.id');
        $attachment = AttendanceAttachment::query()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->withToken($this->token)->get("/api/v1/attendance/attachments/{$attachment->id}")->assertOk();

        $event = $this->rawEvent($this->session, 'workflow-checkin', 'CHECK_IN', $this->session->start_at->addMinutes(45));
        $record = AttendanceRecord::query()->create([
            'shift_session_id' => $this->session->id,
            'employee_id' => $this->employee->id,
            'check_in_event_id' => $event->id,
            'check_in_at' => $event->effective_at,
            'status' => 'IN_PROGRESS',
            'raw_late_minutes' => 45,
            'is_late' => true,
        ]);

        $otherDepartment = Department::query()->where('name', 'Front Office')->value('id');
        $outsideSupervisor = User::factory()->role('supervisor')->create(['department_id' => $otherDepartment]);
        $this->actingAs($outsideSupervisor)->postJson("/admin/api/late-permissions/{$permissionId}/decision", [
            'decision' => 'APPROVED', 'decision_reason' => 'Bukti sesuai',
        ])->assertForbidden();

        $supervisor = User::factory()->role('supervisor')->create(['department_id' => $this->employee->department_id]);
        $this->actingAs($supervisor)->postJson("/admin/api/late-permissions/{$permissionId}/decision", [
            'decision' => 'APPROVED', 'decision_reason' => 'Bukti sesuai',
        ])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->postJson("/admin/api/late-permissions/{$permissionId}/decision", [
            'decision' => 'APPROVED', 'decision_reason' => 'Bukti sesuai',
        ])->assertOk();

        $record->refresh();
        $this->assertSame(45, $record->raw_late_minutes);
        $this->assertTrue($record->is_late);
        $this->assertSame($permissionId, $record->late_permission_id);
        $this->assertDatabaseHas('attendance_events', [
            'id' => $event->id,
            'effective_at' => $event->effective_at->format('Y-m-d H:i:s'),
            'result' => 'ACCEPTED',
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'attendance.late_permission.decided')->count());

        $this->actingAs($supervisor)->get("/admin/api/attachments/{$attachment->id}")->assertOk();
        $this->actingAs(User::factory()->role('supervisor')->create([
            'department_id' => Department::query()->where('name', 'Front Office')->value('id'),
        ]))->get("/admin/api/attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_employee_cannot_approve_own_request_and_direct_permission_is_super_admin_only(): void
    {
        $permission = LatePermission::query()->create([
            'shift_session_id' => $this->session->id,
            'employee_id' => $this->employee->id,
            'requester_id' => $this->employeeUser->id,
            'allowed_minutes' => 60,
            'reason' => 'Transportasi',
            'status' => 'PENDING',
        ]);

        try {
            app(ManageAttendanceWorkflow::class)->decideLatePermission($this->employeeUser, $permission->id, 'APPROVED', 'Saya setujui');
            $this->fail('Requester should not approve their own request.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SELF_APPROVAL', $exception->errorCode);
        }
        $permission->update(['status' => 'CANCELLED']);

        $input = [
            'shift_session_id' => $this->session->id,
            'allowed_minutes' => 60,
            'reason' => 'Izin langsung',
        ];
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)
            ->postJson('/admin/api/late-permissions/direct', $input)->assertForbidden();

        $superAdmin = User::factory()->role('super_admin')->create();
        $this->actingAs($superAdmin)
            ->postJson('/admin/api/late-permissions/direct', $input)
            ->assertCreated()->assertJsonPath('data.status', 'APPROVED');
        $this->assertDatabaseHas('late_permissions', ['direct_by' => $superAdmin->id, 'requester_id' => null]);
    }

    public function test_correction_approval_updates_projection_once_and_keeps_raw_events_unchanged(): void
    {
        $checkIn = $this->session->start_at->addMinutes(20);
        $checkOut = $this->session->end_at->subMinutes(30);
        $inEvent = $this->rawEvent($this->session, 'correction-checkin', 'CHECK_IN', $checkIn);
        $outEvent = $this->rawEvent($this->session, 'correction-checkout', 'CHECK_OUT', $checkOut);
        $record = AttendanceRecord::query()->create([
            'shift_session_id' => $this->session->id,
            'employee_id' => $this->employee->id,
            'check_in_event_id' => $inEvent->id,
            'check_out_event_id' => $outEvent->id,
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'status' => 'VERIFIED',
            'verified_minutes' => 400,
        ]);
        $requestedCheckIn = $this->session->start_at->addMinutes(10);
        $response = $this->withToken($this->token)->postJson('/api/v1/attendance-corrections', [
            'shift_session_id' => $this->session->id,
            'requested_check_in_at' => $requestedCheckIn->toIso8601String(),
            'reason' => 'Perangkat gagal mencatat waktu dengan benar',
        ])->assertCreated()->assertJsonPath('data.status', 'PENDING');
        $correctionId = $response->json('data.id');

        $admin = User::factory()->role('admin')->create();
        $url = "/admin/api/attendance-corrections/{$correctionId}/decision";
        $this->actingAs($admin)->postJson($url, [
            'decision' => 'APPROVED', 'decision_reason' => 'Disetujui berdasarkan bukti',
        ])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $auditCount = AuditLog::query()->count();
        $this->postJson($url, ['decision' => 'APPROVED', 'decision_reason' => 'Disetujui berdasarkan bukti'])->assertOk();

        $record->refresh();
        $correction = AttendanceCorrection::query()->findOrFail($correctionId);
        $this->assertTrue($record->check_in_at->equalTo($requestedCheckIn));
        $this->assertSame(480, $record->verified_minutes);
        $this->assertSame($admin->id, $correction->applied_by);
        $this->assertSame(480, $correction->after_json['verified_minutes']);
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertDatabaseHas('attendance_events', ['id' => $inEvent->id, 'effective_at' => $checkIn->format('Y-m-d H:i:s')]);
        $this->assertDatabaseHas('attendance_events', ['id' => $outEvent->id, 'effective_at' => $checkOut->format('Y-m-d H:i:s')]);
    }

    public function test_checkout_verification_requires_reason_recomputes_minutes_and_is_idempotent(): void
    {
        $checkIn = $this->session->start_at->addMinutes(15);
        $checkOut = $this->session->end_at;
        $record = AttendanceRecord::query()->create([
            'shift_session_id' => $this->session->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $checkIn,
            'provisional_out_at' => $checkOut,
            'status' => 'PROVISIONAL',
            'needs_review' => true,
            'review_reason' => 'AUTO_CHECKOUT',
            'verified_minutes' => 0,
        ]);
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin);
        $url = "/admin/api/attendance/{$this->session->id}/verify";
        $payload = ['check_out_at' => $checkOut->toIso8601String(), 'reason' => 'Dikonfirmasi dari log akses lokasi'];

        $this->postJson($url, ['check_out_at' => $payload['check_out_at']])->assertUnprocessable();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'VERIFIED');
        $auditCount = AuditLog::query()->where('action', 'attendance.checkout.verified')->count();
        $this->postJson($url, $payload)->assertOk();

        $record->refresh();
        $this->assertFalse($record->needs_review);
        $this->assertSame(480, $record->verified_minutes);
        $this->assertSame(1, $auditCount);
        $this->assertSame('Dikonfirmasi dari log akses lokasi', AuditLog::query()->where('action', 'attendance.checkout.verified')->firstOrFail()->after['verification_reason']);
        $this->getJson('/admin/api/dashboard/attendance?from=2026-10-25&to=2026-10-25')
            ->assertOk()->assertJsonPath('summary.review_sessions', 0);
    }

    public function test_direct_correction_is_admin_only_and_keeps_raw_event_unchanged(): void
    {
        $originalCheckIn = $this->session->start_at->addMinutes(20);
        $correctedCheckIn = $this->session->start_at->addMinutes(5);
        $event = $this->rawEvent($this->session, 'direct-correction-checkin', 'CHECK_IN', $originalCheckIn);
        $record = AttendanceRecord::query()->create([
            'shift_session_id' => $this->session->id,
            'employee_id' => $this->employee->id,
            'check_in_event_id' => $event->id,
            'check_in_at' => $originalCheckIn,
            'status' => 'IN_PROGRESS',
        ]);
        $payload = [
            'check_in_at' => $correctedCheckIn->toIso8601String(),
            'check_out_at' => $this->session->end_at->toIso8601String(),
            'reason' => 'Disesuaikan dari catatan supervisor shift',
        ];
        $supervisor = User::factory()->role('supervisor')->create(['department_id' => $this->employee->department_id]);
        $this->actingAs($supervisor)->postJson("/admin/api/attendance-corrections/direct/{$this->session->id}", $payload)
            ->assertForbidden();

        $admin = User::factory()->role('admin')->create();
        $response = $this->actingAs($admin)
            ->postJson("/admin/api/attendance-corrections/direct/{$this->session->id}", $payload)
            ->assertCreated()->assertJsonPath('data.status', 'APPROVED');
        $correction = AttendanceCorrection::query()->findOrFail($response->json('data.id'));

        $record->refresh();
        $this->assertTrue($record->check_in_at->equalTo($correctedCheckIn));
        $this->assertSame('VERIFIED', $record->status);
        $this->assertSame(480, $record->verified_minutes);
        $this->assertNull($correction->requester_id);
        $this->assertSame($admin->id, $correction->applied_by);
        $this->assertSame(480, $correction->after_json['verified_minutes']);
        $this->assertDatabaseHas('attendance_events', [
            'id' => $event->id,
            'effective_at' => $originalCheckIn->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_finalized_period_rejects_request_decision_direct_correction_and_verification(): void
    {
        $permission = LatePermission::query()->create([
            'shift_session_id' => $this->session->id,
            'employee_id' => $this->employee->id,
            'requester_id' => $this->employeeUser->id,
            'allowed_minutes' => 60,
            'reason' => 'Transportasi',
            'status' => 'PENDING',
        ]);
        AttendanceRecord::query()->create([
            'shift_session_id' => $this->session->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $this->session->start_at,
            'provisional_out_at' => $this->session->end_at,
            'status' => 'PROVISIONAL',
            'needs_review' => true,
        ]);
        AttendancePeriod::query()->create(['month' => '2026-10', 'status' => 'FINALIZED']);

        $this->withToken($this->token)->postJson('/api/v1/late-permissions', [
            'shift_session_id' => $this->session->id, 'allowed_minutes' => 45, 'reason' => 'Terlambat',
        ])->assertConflict()->assertJsonPath('code', 'PERIOD_LOCKED');

        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin);
        $this->postJson("/admin/api/late-permissions/{$permission->id}/decision", [
            'decision' => 'APPROVED', 'decision_reason' => 'Disetujui',
        ])->assertConflict()->assertJsonPath('code', 'PERIOD_LOCKED');
        $this->postJson("/admin/api/attendance-corrections/direct/{$this->session->id}", [
            'check_in_at' => $this->session->start_at->toIso8601String(), 'reason' => 'Perbaikan',
        ])->assertConflict()->assertJsonPath('code', 'PERIOD_LOCKED');
        $this->postJson("/admin/api/attendance/{$this->session->id}/verify", [
            'check_out_at' => $this->session->end_at->toIso8601String(), 'reason' => 'Log akses',
        ])->assertConflict()->assertJsonPath('code', 'PERIOD_LOCKED');

        $this->assertSame('PENDING', $permission->fresh()->status);
        $this->assertSame('PROVISIONAL', AttendanceRecord::query()->where('shift_session_id', $this->session->id)->value('status'));
    }

    public function test_late_permission_rejects_until_and_minutes_together(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/late-permissions', [
                'shift_session_id' => $this->session->id,
                'allowed_until' => '2026-10-25T01:30:00Z',
                'allowed_minutes' => 30,
                'reason' => 'Terlambat hadir',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('details.allowed_until.0', 'Isi jam target hadir atau durasi izin, jangan keduanya.');
    }

    public function test_policy_proof_settings_persist_and_partial_updates_keep_existing_values(): void
    {
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin);
        $created = $this->postJson('/admin/api/policies', [
            'scope_type' => 'global',
            'grace_minutes' => 10,
            'use_location_check' => false,
            'late_permission_proof_required' => true,
            'correction_proof_required' => true,
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $policyId = $created->json('data.id');
        $this->putJson("/admin/api/policies/{$policyId}", [
            'scope_type' => 'global',
            'grace_minutes' => 15,
            'use_location_check' => false,
            'effective_from' => '2026-01-01',
        ])->assertOk()
            ->assertJsonPath('data.late_permission_proof_required', true)
            ->assertJsonPath('data.correction_proof_required', true);

        $this->assertTrue((bool) AttendancePolicy::query()->findOrFail($policyId)->late_permission_proof_required);
    }

    private function rawEvent(ShiftSession $session, string $uuidSuffix, string $type, $effectiveAt): AttendanceEvent
    {
        return AttendanceEvent::query()->create([
            'event_uuid' => 'wf-'.str_replace('-', '', $uuidSuffix),
            'payload_hash' => hash('sha256', $uuidSuffix),
            'employee_id' => $this->employee->id,
            'shift_session_id' => $session->id,
            'type' => $type,
            'source' => 'ONLINE',
            'server_received_at' => now(),
            'effective_at' => $effectiveAt,
            'result' => 'ACCEPTED',
        ]);
    }
}

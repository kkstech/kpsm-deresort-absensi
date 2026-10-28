<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAttendance;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use App\Models\DeviceRegistration;
use App\Models\Employee;
use App\Models\ShiftAssignment;
use App\Models\SyncBatch;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    public function schedule(Request $request): JsonResponse
    {
        $range = $this->range($request);
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => []]);
        }
        if ($employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
        }
        $assignments = ShiftAssignment::query()
            ->with('sessions.attendanceRecord')
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$range['from'], $range['to']])
            ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
            ->orderBy('work_date')
            ->get();

        return response()->json(['data' => $assignments->map(fn ($assignment) => $this->assignmentData($assignment))->all()]);
    }

    public function storeEvent(Request $request, RecordAttendance $attendance): JsonResponse
    {
        $input = $this->eventInput($request->all());
        $device = $this->device($request, $input['device_id']);
        $result = $attendance->handle($this->employee($request), $input, $device->id);

        return response()->json(['data' => $result['data']], $result['http']);
    }

    public function eventReceipt(Request $request, string $uuid): JsonResponse
    {
        $employee = $this->employee($request);
        $event = AttendanceEvent::query()->where('event_uuid', $uuid)->where('employee_id', $employee->id)->first();
        if (! $event) {
            return response()->json(['data' => null, 'received' => false], 404);
        }

        return response()->json(['received' => true, 'data' => [
            'event_uuid' => $event->event_uuid,
            'event_type' => $event->type,
            'status' => $event->result,
            'reason_code' => $event->reason_code,
            'effective_at' => WorkTime::iso($event->effective_at),
            'server_received_at' => WorkTime::iso($event->server_received_at),
        ]]);
    }

    public function sync(Request $request, RecordAttendance $attendance): JsonResponse
    {
        $batchInput = $request->validate([
            'batch_uuid' => ['required', 'uuid'],
            'events' => ['required', 'array', 'min:1', 'max:100'],
        ]);
        $employee = $this->employee($request);
        $device = $request->attributes->get('device_registration');
        $batch = SyncBatch::query()->where('batch_uuid', $batchInput['batch_uuid'])->first();
        if ($batch && ($batch->user_id !== $request->user()->id || $batch->device_registration_id !== $device->id)) {
            throw new BusinessRuleException('BATCH_UUID_CONFLICT', 'batch_uuid sudah digunakan perangkat lain.');
        }
        if (! $batch) {
            try {
                $batch = SyncBatch::query()->create([
                    'batch_uuid' => $batchInput['batch_uuid'],
                    'user_id' => $request->user()->id,
                    'device_registration_id' => $device->id,
                    'event_count' => count($batchInput['events']),
                    'received_at' => WorkTime::now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $batch = SyncBatch::query()->where('batch_uuid', $batchInput['batch_uuid'])->firstOrFail();
                if ($batch->user_id !== $request->user()->id || $batch->device_registration_id !== $device->id) {
                    throw new BusinessRuleException('BATCH_UUID_CONFLICT', 'batch_uuid sudah digunakan perangkat lain.');
                }
            }
        }

        $events = $batchInput['events'];
        $ordered = $events;
        if (collect($events)->every(fn ($event) => isset($event['local_sequence']))) {
            usort($ordered, fn ($a, $b) => $a['local_sequence'] <=> $b['local_sequence']);
        } elseif (collect($events)->every(fn ($event) => isset($event['device_at']))) {
            usort($ordered, fn ($a, $b) => CarbonImmutable::parse($a['device_at'])->getTimestamp() <=> CarbonImmutable::parse($b['device_at'])->getTimestamp());
        }

        $results = [];
        foreach ($ordered as $event) {
            $uuid = $event['event_uuid'] ?? null;
            $validator = Validator::make($event, $this->eventRules());
            if ($validator->fails()) {
                $results[] = [
                    'event_uuid' => $uuid,
                    'status' => 'REJECTED',
                    'reason_code' => 'VALIDATION_ERROR',
                    'details' => $validator->errors(),
                ];

                continue;
            }

            try {
                $input = $validator->validated();
                $this->device($request, $input['device_id']);
                $results[] = $attendance->handle($employee, $input, $device->id, $batch->id)['data'];
            } catch (BusinessRuleException $exception) {
                $results[] = [
                    'event_uuid' => $uuid,
                    'status' => 'REJECTED',
                    'reason_code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $batch->update(['event_count' => count($batchInput['events']), 'summary' => $results, 'received_at' => WorkTime::now()]);

        return response()->json(['batch_uuid' => $batch->batch_uuid, 'results' => $results, 'server_time' => WorkTime::iso(WorkTime::now())]);
    }

    public function history(Request $request): JsonResponse
    {
        $range = $this->range($request);
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => []]);
        }
        if ($employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
        }
        $assignments = ShiftAssignment::query()
            ->with('sessions.attendanceRecord', 'sessions.attendanceEvents')
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$range['from'], $range['to']])
            ->orderBy('work_date')
            ->get();

        return response()->json(['data' => $assignments->map(fn ($assignment) => $this->assignmentData($assignment, true))->all()]);
    }

    private function eventRules(): array
    {
        return [
            'event_uuid' => ['required', 'uuid'],
            'shift_session_id' => ['required', 'integer', 'exists:shift_sessions,id'],
            'event_type' => ['required', 'in:CHECK_IN,CHECK_OUT'],
            'device_id' => ['required', 'string', 'max:64'],
            'device_timezone' => ['nullable', 'string', 'max:64'],
            'captured_offline' => ['sometimes', 'boolean'],
            'device_at' => ['nullable', 'required_if:captured_offline,true', 'date'],
            'local_sequence' => ['nullable', 'integer', 'min:0'],
            'location' => ['nullable', 'array:latitude,longitude,accuracy_meters'],
            'location.latitude' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.longitude' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    private function eventInput(array $input): array
    {
        return Validator::make($input, $this->eventRules())->validate();
    }

    private function range(Request $request): array
    {
        $range = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        if (CarbonImmutable::parse($range['from'])->diffInDays(CarbonImmutable::parse($range['to'])) > 62) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Rentang paling panjang 62 hari.', 422);
        }

        return $range;
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        if (! $employee || $employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
        }

        return $employee;
    }

    private function device(Request $request, string $deviceUuid): DeviceRegistration
    {
        $device = $request->attributes->get('device_registration');
        if ($device->device_uuid !== $deviceUuid) {
            throw new BusinessRuleException('DEVICE_MISMATCH', 'device_id tidak cocok dengan token aktif.', 403);
        }

        return $device;
    }

    private function assignmentData(ShiftAssignment $assignment, bool $includeEvents = false): array
    {
        $data = $assignment->toApi();
        $overtimes = \App\Models\OvertimeOrder::query()
            ->where('employee_id', $assignment->employee_id)
            ->where('work_date', $assignment->work_date)
            ->where('status', 'APPROVED')
            ->where('consent_status', 'ACCEPTED')
            ->get();

        foreach ($assignment->sessions as $index => $session) {
            $data['sessions'][$index]['attendance'] = $session->attendanceRecord?->toApi();
            $sessionOvertime = $overtimes->first(fn ($o) => $o->shift_session_id === $session->id || $overtimes->count() === 1);
            if ($sessionOvertime) {
                $data['sessions'][$index]['overtime'] = [
                    'id' => $sessionOvertime->id,
                    'order_number' => $sessionOvertime->order_number,
                    'title' => $sessionOvertime->title,
                    'start_at' => WorkTime::iso($sessionOvertime->start_at),
                    'end_at' => WorkTime::iso($sessionOvertime->end_at),
                    'local_start' => WorkTime::local($sessionOvertime->start_at)->format('H:i'),
                    'local_end' => WorkTime::local($sessionOvertime->end_at)->format('H:i'),
                    'planned_minutes' => $sessionOvertime->planned_minutes,
                    'verified_minutes' => $sessionOvertime->verified_minutes,
                    'is_day_off' => (bool) $sessionOvertime->is_day_off,
                ];
            }
            if ($includeEvents) {
                $data['sessions'][$index]['events'] = $session->attendanceEvents->map(fn (AttendanceEvent $event) => [
                    'event_uuid' => $event->event_uuid,
                    'event_type' => $event->type,
                    'source' => $event->source,
                    'status' => $event->result,
                    'reason_code' => $event->reason_code,
                    'effective_at' => WorkTime::iso($event->effective_at),
                    'server_received_at' => WorkTime::iso($event->server_received_at),
                ])->all();
            }
        }

        return $data;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShiftAssignment;
use App\Models\ShiftTemplate;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ShiftTemplate::query()->with(['sessions', 'category'])->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        [$data, $sessions] = $this->validated($request);

        $template = DB::transaction(function () use ($data, $sessions) {
            $template = ShiftTemplate::query()->create([
                ...$data,
                'category_first_assigned_at' => empty($data['shift_category_id']) ? null : now(),
            ]);
            $template->sessions()->createMany($sessions);
            Audit::log('templates.created', $template, null, [...$data, 'sessions' => $sessions]);

            return $template;
        });

        return response()->json(['data' => $template->load('sessions')], 201);
    }

    /** Jadwal yang sudah dibuat menyimpan salinan jam sendiri, jadi ubah template tidak mengubah jadwal lama. */
    public function update(Request $request, ShiftTemplate $template): JsonResponse
    {
        Gate::authorize('admin');
        [$data, $sessions] = $this->validated($request, $template);

        DB::transaction(function () use ($template, $data, $sessions) {
            $before = [...$template->attributesToArray(), 'sessions' => $template->sessions->toArray()];
            $firstCategory = $template->category_first_assigned_at === null && ! empty($data['shift_category_id']);
            if ($firstCategory) {
                $data['category_first_assigned_at'] = now();
            }
            $template->update($data);
            $template->sessions()->delete();
            $template->sessions()->createMany($sessions);
            if ($firstCategory) {
                ShiftAssignment::query()
                    ->where('shift_template_id', $template->id)
                    ->whereNull('shift_category_id')
                    ->update(['shift_category_id' => $data['shift_category_id']]);
            }
            Audit::log('templates.updated', $template, $before, [...$data, 'sessions' => $sessions]);
        });

        return response()->json(['data' => $template->fresh('sessions')]);
    }

    public function destroy(ShiftTemplate $template): JsonResponse
    {
        Gate::authorize('admin');
        $before = $template->attributesToArray();
        $template->delete();
        Audit::log('templates.deleted', $template, $before, []);

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?ShiftTemplate $template = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('shift_templates', 'name')->ignore($template?->id)],
            'type' => ['required', 'in:regular,special'],
            'shift_category_id' => ['nullable', 'integer', Rule::exists('shift_categories', 'id')->where('is_active', true)],
            'repeat_pattern' => ['nullable', 'in:weekly'],
            'sessions' => ['required', 'array', 'min:1', 'max:4'],
            'sessions.*.local_start' => ['required', 'date_format:H:i'],
            'sessions.*.local_end' => ['required', 'date_format:H:i'],
            'sessions.*.unpaid_break_minutes' => ['nullable', 'integer', 'between:0,240'],
        ]);

        if ($data['type'] === 'regular' && count($data['sessions']) !== 1) {
            throw ValidationException::withMessages(['sessions' => 'Shift reguler hanya punya 1 sesi.']);
        }
        if ($data['type'] === 'special' && count($data['sessions']) < 2) {
            throw ValidationException::withMessages(['sessions' => 'Shift split minimal memiliki 2 sesi.']);
        }

        $sessions = [];
        $previousEnd = -1;
        $totalWorkMinutes = 0;
        foreach (array_values($data['sessions']) as $i => $row) {
            $start = $this->minutes($row['local_start']);
            $end = $this->minutes($row['local_end']);
            $startOffset = (int) ($row['start_day_offset'] ?? 0);
            $offset = isset($row['day_offset']) ? (int) $row['day_offset'] : $startOffset + ($end <= $start ? 1 : 0);
            $absoluteStart = $start + $startOffset * 1440;
            $absoluteEnd = $end + $offset * 1440;
            $length = $absoluteEnd - $absoluteStart;
            $break = (int) ($row['unpaid_break_minutes'] ?? 0);

            if (($i === 0 && $startOffset !== 0) || $startOffset > 1 || $offset > 2 || $length <= 0) {
                throw ValidationException::withMessages(["sessions.{$i}.local_end" => 'Urutan hari pada sesi tidak valid.']);
            }
            if ($absoluteStart < $previousEnd) {
                throw ValidationException::withMessages(["sessions.{$i}.local_start" => 'Sesi '.($i + 1).' bertabrakan dengan sesi sebelumnya.']);
            }
            if ($length > 12 * 60) {
                throw ValidationException::withMessages(["sessions.{$i}.local_end" => 'Satu sesi paling lama 12 jam.']);
            }
            if ($break >= $length) {
                throw ValidationException::withMessages(["sessions.{$i}.unpaid_break_minutes" => 'Istirahat harus lebih pendek dari sesi.']);
            }
            $previousEnd = $absoluteEnd;
            $totalWorkMinutes += $length - $break;
            $sessions[] = ['seq' => $i + 1, 'local_start' => $row['local_start'], 'local_end' => $row['local_end'], 'start_day_offset' => $startOffset, 'day_offset' => $offset, 'unpaid_break_minutes' => $break];
        }

        $firstStart = $this->minutes($data['sessions'][0]['local_start']) + ((int) ($data['sessions'][0]['start_day_offset'] ?? 0)) * 1440;
        $last = $data['sessions'][array_key_last($data['sessions'])];
        $lastEnd = $this->minutes($last['local_end']) + (isset($last['day_offset']) ? (int) $last['day_offset'] : ((int) ($last['start_day_offset'] ?? 0) + ($this->minutes($last['local_end']) <= $this->minutes($last['local_start']) ? 1 : 0))) * 1440;
        if ($lastEnd - $firstStart > 24 * 60) {
            throw ValidationException::withMessages(['sessions' => 'Rentang shift maksimal 24 jam.']);
        }
        $maxWorkMinutes = $data['type'] === 'special' ? 16 * 60 : 12 * 60;
        if ($totalWorkMinutes > $maxWorkMinutes) {
            throw ValidationException::withMessages(['sessions' => 'Total kerja satu shift maksimal '.($maxWorkMinutes / 60).' jam.']);
        }
        if ($data['type'] === 'special' && count($sessions) > 4) {
            throw ValidationException::withMessages(['sessions' => 'Shift split maksimal memiliki 4 sesi.']);
        }

        unset($data['sessions']);

        return [$data, $sessions];
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}

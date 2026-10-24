<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        return response()->json(['data' => ShiftTemplate::query()->with('sessions')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        [$data, $sessions] = $this->validated($request);

        $template = DB::transaction(function () use ($data, $sessions) {
            $template = ShiftTemplate::query()->create($data);
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
            $template->update($data);
            $template->sessions()->delete();
            $template->sessions()->createMany($sessions);
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
            'repeat_pattern' => ['nullable', 'in:weekly'],
            'sessions' => ['required', 'array', 'min:1', 'max:4'],
            'sessions.*.local_start' => ['required', 'date_format:H:i'],
            'sessions.*.local_end' => ['required', 'date_format:H:i'],
            'sessions.*.unpaid_break_minutes' => ['nullable', 'integer', 'between:0,240'],
        ]);

        if ($data['type'] === 'regular' && count($data['sessions']) !== 1) {
            throw ValidationException::withMessages(['sessions' => 'Shift reguler hanya punya 1 sesi.']);
        }

        $sessions = [];
        $previousEnd = -1;
        foreach (array_values($data['sessions']) as $i => $row) {
            $start = $this->minutes($row['local_start']);
            $end = $this->minutes($row['local_end']);
            $offset = $end <= $start ? 1 : 0;
            $length = $end + $offset * 1440 - $start;
            $break = (int) ($row['unpaid_break_minutes'] ?? 0);

            if ($start < $previousEnd) {
                throw ValidationException::withMessages(["sessions.{$i}.local_start" => 'Sesi '.($i + 1).' bertabrakan dengan sesi sebelumnya.']);
            }
            if ($length > 16 * 60) {
                throw ValidationException::withMessages(["sessions.{$i}.local_end" => 'Satu sesi paling lama 16 jam.']);
            }
            if ($break >= $length) {
                throw ValidationException::withMessages(["sessions.{$i}.unpaid_break_minutes" => 'Istirahat harus lebih pendek dari sesi.']);
            }
            if ($offset && $i < count($data['sessions']) - 1) {
                throw ValidationException::withMessages(["sessions.{$i}.local_end" => 'Sesi yang lewat tengah malam harus jadi sesi terakhir.']);
            }

            $previousEnd = $end + $offset * 1440;
            $sessions[] = ['seq' => $i + 1, 'local_start' => $row['local_start'], 'local_end' => $row['local_end'], 'day_offset' => $offset, 'unpaid_break_minutes' => $break];
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

<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Akun admin web (super admin, admin, supervisor). Akun karyawan diatur dari menu Karyawan. */
class UserController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('super-admin');

        return response()->json(['data' => User::query()
            ->whereIn('role', User::WEB_ROLES)
            ->with(['department:id,name', 'employee:id,code,name,user_id,department_id,position_id'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role', 'is_active', 'can_view_all', 'department_id'])]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('super-admin');
        $data = $this->validated($request);
        $user = User::query()->create([...$data, 'email' => Str::lower($data['email'])]);
        Audit::log('users.created', $user);

        return response()->json(['data' => $user], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        Gate::authorize('super-admin');
        abort_unless(in_array($user->role, User::WEB_ROLES, true), 404);
        $data = $this->validated($request, $user);

        if ($user->is($request->user()) && ($data['role'] !== 'super_admin' || ! $data['is_active'])) {
            throw new BusinessRuleException('SELF_LOCKOUT', 'Anda tidak bisa menurunkan atau menonaktifkan akun sendiri.');
        }

        $before = $user->attributesToArray();
        $user->update([...$data, 'email' => Str::lower($data['email'])]);
        if (! $user->is_active) {
            $user->tokens()->delete();
        }
        Audit::log('users.updated', $user, $before);

        return response()->json(['data' => $user]);
    }

    public function linkEmployee(Request $request, User $user): JsonResponse
    {
        Gate::authorize('super-admin');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        if ($employee->user_id && $employee->user_id !== $user->id) {
            throw new BusinessRuleException('EMPLOYEE_ALREADY_LINKED', 'Karyawan ini sudah terhubung ke akun user lain.', 422);
        }

        DB::transaction(function () use ($user, $employee) {
            Employee::query()->where('user_id', $user->id)->where('id', '!=', $employee->id)->update(['user_id' => null]);

            $updates = ['user_id' => $user->id];
            if (empty($employee->email) && ! empty($user->email)) {
                $updates['email'] = $user->email;
            }
            if (empty($employee->phone) && ! empty($user->phone)) {
                $updates['phone'] = $user->phone;
            }
            $employee->update($updates);

            Audit::log('users.employee_linked', $user, null, ['employee_id' => $employee->id]);
        });

        return response()->json(['data' => $user->fresh(['department:id,name', 'employee:id,code,name,user_id,department_id,position_id'])]);
    }

    public function unlinkEmployee(Request $request, User $user): JsonResponse
    {
        Gate::authorize('super-admin');
        $employee = $user->employee;
        if (! $employee) {
            throw new BusinessRuleException('NOT_LINKED', 'Akun user ini belum terhubung ke data karyawan.', 422);
        }

        DB::transaction(function () use ($user, $employee) {
            $employee->update(['user_id' => null]);
            Audit::log('users.employee_unlinked', $user, null, ['employee_id' => $employee->id]);
        });

        return response()->json(['data' => $user->fresh(['department:id,name', 'employee:id,code,name,user_id,department_id,position_id'])]);
    }

    public function createEmployee(Request $request, User $user): JsonResponse
    {
        Gate::authorize('super-admin');
        if ($user->employee()->exists()) {
            throw new BusinessRuleException('USER_HAS_EMPLOYEE', 'Akun user ini sudah memiliki data karyawan.', 422);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:employees,code'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'position_id' => ['required', 'integer', 'exists:positions,id'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'started_on' => ['required', 'date_format:Y-m-d'],
        ]);

        DB::transaction(function () use ($user, $data) {
            $employee = Employee::query()->create([
                'code' => $data['code'],
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'department_id' => $data['department_id'],
                'position_id' => $data['position_id'],
                'work_location_id' => $data['work_location_id'] ?? null,
                'started_on' => $data['started_on'],
                'status' => 'active',
                'user_id' => $user->id,
            ]);

            $employee->placements()->create([
                'department_id' => $employee->department_id,
                'effective_from' => $employee->started_on,
            ]);

            $employee->employmentTerms()->create([
                'type' => 'permanent',
                'starts_on' => $employee->started_on,
            ]);

            Audit::log('users.employee_created', $user, null, ['employee_id' => $employee->id]);
        });

        return response()->json(['data' => $user->fresh(['department:id,name', 'employee:id,code,name,user_id,department_id,position_id'])], 201);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in(User::WEB_ROLES)],
            'department_id' => [$request->input('role') === 'supervisor' && ! $request->boolean('can_view_all') ? 'required' : 'nullable', 'integer', 'exists:departments,id'],
            'can_view_all' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}

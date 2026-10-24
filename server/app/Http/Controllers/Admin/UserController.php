<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->with('department:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'can_view_all', 'department_id'])]);
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

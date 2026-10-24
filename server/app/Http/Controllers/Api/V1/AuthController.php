<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\DeviceRegistration;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_uuid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', Str::lower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new BusinessRuleException('INVALID_CREDENTIALS', 'Email atau password salah.', 401);
        }
        if (! $user->is_active) {
            throw new BusinessRuleException('ACCOUNT_INACTIVE', 'Akun tidak aktif.', 403);
        }
        if (! $user->employee) {
            throw new BusinessRuleException('NO_EMPLOYEE', 'Akun ini belum terhubung ke data karyawan.', 403);
        }

        $token = DB::transaction(function () use ($user, $data) {
            $device = DeviceRegistration::query()->updateOrCreate(
                ['user_id' => $user->id, 'device_uuid' => $data['device_uuid']],
                ['name' => $data['device_name'] ?? null, 'last_seen_at' => now(), 'revoked_at' => null],
            );
            $user->tokens()->where('name', "device:{$device->id}")->delete();

            return $user->createToken("device:{$device->id}")->plainTextToken;
        });

        return response()->json(['token' => $token, 'user' => $this->profile($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    private function profile(User $user): array
    {
        $employee = $user->employee?->loadMissing('department:id,name', 'position:id,name');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'employee' => $employee ? [
                'id' => $employee->id,
                'code' => $employee->code,
                'name' => $employee->name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
            ] : null,
        ];
    }
}

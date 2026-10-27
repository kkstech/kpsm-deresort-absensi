<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\DeviceRegistration;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:255'],
            'device_uuid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $rawEmail = isset($data['email']) ? trim($data['email']) : null;
        $rawPhone = isset($data['phone']) ? trim($data['phone']) : null;

        if (empty($rawEmail) && empty($rawPhone)) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Email pribadi atau nomor telepon wajib diisi.', 422);
        }

        $user = $this->resolveUser($rawEmail, $rawPhone);
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new BusinessRuleException('INVALID_CREDENTIALS', 'Email pribadi, nomor telepon, atau password salah.', 401);
        }
        if (! $user->is_active) {
            throw new BusinessRuleException('ACCOUNT_INACTIVE', 'Akun tidak aktif.', 403);
        }
        if (! $user->employee && ! in_array($user->role, User::WEB_ROLES, true)) {
            throw new BusinessRuleException('NO_EMPLOYEE', 'Akun ini belum terhubung ke data karyawan.', 403);
        }

        if (! empty($rawPhone)) {
            $norm = static::normalizePhone($rawPhone);
            if ($norm) {
                if (! $user->phone) {
                    $user->update(['phone' => $norm]);
                }
                if ($user->employee && ! $user->employee->phone) {
                    $user->employee->update(['phone' => $norm]);
                }
            }
        }

        $token = DB::transaction(function () use ($user, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->is_active) {
                throw new BusinessRuleException('ACCOUNT_INACTIVE', 'Akun tidak aktif.', 403);
            }
            if (! $user->employee()->exists() && ! in_array($user->role, User::WEB_ROLES, true)) {
                throw new BusinessRuleException('NO_EMPLOYEE', 'Akun ini belum terhubung ke data karyawan.', 403);
            }

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

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['required_with:password', 'nullable', 'string'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if (! empty($data['password'])) {
            if (! Hash::check($data['current_password'] ?? '', $user->password)) {
                throw new BusinessRuleException('INVALID_PASSWORD', 'Kata sandi saat ini tidak cocok.', 422);
            }
            $user->password = $data['password'];
        }

        if (isset($data['name'])) {
            $user->name = $data['name'];
            if ($employee) {
                $employee->name = $data['name'];
            }
        }

        if (array_key_exists('phone', $data)) {
            $normPhone = static::normalizePhone($data['phone']);
            $user->phone = $normPhone;
            if ($employee) {
                $employee->phone = $normPhone;
            }
        }

        if (isset($data['email'])) {
            $lowerEmail = Str::lower($data['email']);
            $user->email = $lowerEmail;
            if ($employee) {
                $employee->email = $lowerEmail;
            }
        }

        DB::transaction(function () use ($user, $employee) {
            $user->save();
            $employee?->save();
            Audit::log('user.profile_updated', $user, null, [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ]);
        });

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => $this->profile($user->fresh(['employee.department', 'employee.position'])),
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'max:5120'],
        ]);

        $file = $request->file('avatar');
        if (! $file || ! $file->isValid()) {
            return response()->json([
                'code' => 'INVALID_FILE',
                'message' => 'File gambar tidak dapat dibaca.',
            ], 422);
        }

        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        if (! in_array($mime, $allowed, true) && ! str_starts_with((string) $mime, 'image/')) {
            return response()->json([
                'code' => 'INVALID_IMAGE_TYPE',
                'message' => 'Format file harus berupa gambar (JPG, PNG, atau WEBP).',
            ], 422);
        }

        $user = $request->user();
        $employee = $user->employee;

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $filename = 'avatar_' . $user->id . '_' . time() . '.' . $ext;
        $path = $file->storeAs('avatars', $filename, 'public');

        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        DB::transaction(function () use ($user, $employee, $path) {
            $user->update(['avatar_path' => $path]);
            if ($employee) {
                $employee->update(['avatar_path' => $path]);
            }
            Audit::log('user.avatar_updated', $user, null, ['avatar_path' => $path]);
        });

        return response()->json([
            'message' => 'Foto profil berhasil diperbarui.',
            'user' => $this->profile($user->fresh(['employee.department', 'employee.position'])),
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        DB::transaction(function () use ($user, $employee) {
            $user->update(['avatar_path' => null]);
            if ($employee) {
                $employee->update(['avatar_path' => null]);
            }
            Audit::log('user.avatar_deleted', $user);
        });

        return response()->json([
            'message' => 'Foto profil berhasil dihapus.',
            'user' => $this->profile($user->fresh(['employee.department', 'employee.position'])),
        ]);
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));
        if (str_starts_with($cleaned, '+62')) {
            return '0'.substr($cleaned, 3);
        }
        if (str_starts_with($cleaned, '62') && strlen($cleaned) >= 10) {
            return '0'.substr($cleaned, 2);
        }

        return $cleaned;
    }

    private function resolveUser(?string $rawEmail, ?string $rawPhone): ?User
    {
        // 1. Try finding by email (if email format or provided in rawEmail)
        if (! empty($rawEmail) && str_contains($rawEmail, '@')) {
            $normalizedEmail = Str::lower($rawEmail);
            $user = User::query()->where('email', $normalizedEmail)->first();
            if ($user) {
                return $user;
            }
            $employee = Employee::query()->where('email', $normalizedEmail)->whereNotNull('user_id')->first();
            if ($employee && $employee->user) {
                return $employee->user;
            }
        }

        // 2. Identify candidate phone
        $candidatePhone = ! empty($rawPhone) ? $rawPhone : (! empty($rawEmail) && ! str_contains($rawEmail, '@') ? $rawEmail : null);

        if ($candidatePhone) {
            $normalizedPhone = static::normalizePhone($candidatePhone);

            $user = User::query()
                ->where(function ($q) use ($candidatePhone, $normalizedPhone) {
                    $q->where('phone', $candidatePhone);
                    if ($normalizedPhone) {
                        $q->orWhere('phone', $normalizedPhone);
                    }
                })
                ->first();
            if ($user) {
                return $user;
            }

            $employee = Employee::query()
                ->whereNotNull('user_id')
                ->where(function ($q) use ($candidatePhone, $normalizedPhone) {
                    $q->where('phone', $candidatePhone);
                    if ($normalizedPhone) {
                        $q->orWhere('phone', $normalizedPhone);
                    }
                })
                ->first();

            if (! $employee && $normalizedPhone) {
                $employee = Employee::query()
                    ->whereNotNull('user_id')
                    ->whereNotNull('phone')
                    ->get()
                    ->first(fn ($emp) => static::normalizePhone($emp->phone) === $normalizedPhone);
            }

            if ($employee && $employee->user) {
                return $employee->user;
            }
        }

        // 3. Fallback: by employee code if entered in rawEmail
        if (! empty($rawEmail)) {
            $employee = Employee::query()->where('code', $rawEmail)->whereNotNull('user_id')->first();
            if ($employee && $employee->user) {
                return $employee->user;
            }
        }

        return null;
    }

    private function profile(User $user): array
    {
        $employee = $user->employee?->loadMissing('department:id,name', 'position:id,name');
        $avatarPath = $user->avatar_path ?? $employee?->avatar_path;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? $employee?->phone,
            'role' => $user->role,
            'avatar_path' => $avatarPath ? '/storage/' . ltrim($avatarPath, '/') : null,
            'avatar_url' => $avatarPath ? url('storage/' . ltrim($avatarPath, '/')) : null,
            'employee' => $employee ? [
                'id' => $employee->id,
                'code' => $employee->code,
                'name' => $employee->name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'avatar_path' => $employee->avatar_path ? '/storage/' . ltrim($employee->avatar_path, '/') : null,
                'avatar_url' => $employee->avatar_path ? url('storage/' . ltrim($employee->avatar_path, '/')) : null,
            ] : null,
        ];
    }
}

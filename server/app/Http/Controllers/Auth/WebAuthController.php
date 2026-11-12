<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebAuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        return Auth::check()
            ? redirect()->route('admin.dashboard')
            : view('auth.login', ['siteSettings' => SiteSetting::current()]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $rawIdentifier = trim($data['username'] ?? $data['email'] ?? '');
        if (empty($rawIdentifier)) {
            throw ValidationException::withMessages([
                'username' => 'Username wajib diisi.',
                'email' => 'Username wajib diisi.',
            ]);
        }

        $lower = Str::lower($rawIdentifier);
        $user = User::query()
            ->where('username', $lower)
            ->orWhere('email', $lower)
            ->first();

        if (! $user) {
            $employee = \App\Models\Employee::query()
                ->where(function ($q) use ($rawIdentifier, $lower) {
                    $q->where('code', $rawIdentifier)
                        ->orWhere('code', Str::upper($rawIdentifier))
                        ->orWhere('code', $lower);
                })
                ->first();
            if ($employee && $employee->user) {
                $user = $employee->user;
            }
        }

        $allowed = $user && $user->is_active && in_array($user->role, User::WEB_ROLES, true);

        if (! $allowed || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => 'Username atau password salah.',
                'email' => 'Username atau password salah.',
            ]);
        }

        $remember = (bool) ($data['remember'] ?? false);
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->forget(['auth.reauthenticated_at', 'auth.reauthenticated_user_id']);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DatabaseBackupService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DatabaseBackupController extends Controller
{
    public function confirm(Request $request): JsonResponse
    {
        Gate::authorize('super-admin');
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            return response()->json([
                'code' => 'INVALID_PASSWORD',
                'message' => 'Kata sandi tidak cocok.',
            ], 422);
        }

        $request->session()->put([
            'auth.reauthenticated_at' => now()->timestamp,
            'auth.reauthenticated_user_id' => $request->user()->id,
        ]);
        Audit::log('database_backup.reauthenticated', $request->user(), null, ['ip' => $request->ip()]);

        return response()->json(['data' => ['confirmed' => true]]);
    }

    public function status(DatabaseBackupService $backups): JsonResponse
    {
        Gate::authorize('super-admin');

        return response()->json(['data' => [
            'supported' => $backups->isSupported(),
            'has_safety_backup' => $backups->hasSafetyBackup(),
        ]]);
    }

    public function download(Request $request, DatabaseBackupService $backups): BinaryFileResponse|JsonResponse
    {
        Gate::authorize('super-admin');

        if (! $backups->isSupported()) {
            return $this->unsupportedResponse();
        }

        try {
            $path = $backups->createBackup();
        } catch (Throwable $error) {
            report($error);

            return response()->json([
                'code' => 'DATABASE_BACKUP_FAILED',
                'message' => 'Backup database gagal dibuat. Coba lagi atau hubungi IT.',
            ], 500);
        }
        Audit::log('database_backup.downloaded', $request->user(), null, [
            'kind' => 'current',
            'filename' => basename($path),
            'ip' => $request->ip(),
        ]);

        return response()->download(
            $path,
            'deresort-database-'.now()->format('Ymd-His').'.sql.gz',
            ['Content-Type' => 'application/gzip', 'Cache-Control' => 'private, no-store']
        )->deleteFileAfterSend(true);
    }

    public function downloadSafetyBackup(Request $request, DatabaseBackupService $backups): BinaryFileResponse|JsonResponse
    {
        Gate::authorize('super-admin');

        if (! $backups->isSupported()) {
            return $this->unsupportedResponse();
        }
        if (! $backups->hasSafetyBackup()) {
            return response()->json([
                'code' => 'SAFETY_BACKUP_NOT_FOUND',
                'message' => 'Belum ada backup pengaman.',
            ], 404);
        }
        Audit::log('database_backup.downloaded', $request->user(), null, [
            'kind' => 'safety',
            'filename' => basename($backups->safetyBackupPath()),
            'ip' => $request->ip(),
        ]);

        return response()->download(
            $backups->safetyBackupPath(),
            'deresort-database-safety.sql.gz',
            ['Content-Type' => 'application/gzip', 'Cache-Control' => 'private, no-store']
        );
    }

    public function restore(Request $request, DatabaseBackupService $backups): JsonResponse
    {
        Gate::authorize('super-admin');

        if (! $backups->isSupported()) {
            return $this->unsupportedResponse();
        }

        $data = $request->validate([
            'backup' => ['required', 'file', 'extensions:gz', 'max:102400'],
            'confirmation' => ['required', 'string', 'in:RESTORE'],
        ]);

        try {
            $backups->restore((string) $data['backup']->getRealPath());
        } catch (InvalidArgumentException $error) {
            return response()->json([
                'code' => 'INVALID_DATABASE_BACKUP',
                'message' => $error->getMessage(),
            ], 422);
        } catch (Throwable $error) {
            report($error);
            $message = match ($error->getMessage()) {
                'safety_backup_failed' => 'Restore dihentikan karena backup pengaman gagal dibuat. Database belum diganti.',
                'restore_failed_and_recovered' => 'Restore gagal. Database sebelumnya sudah dipulihkan dari backup pengaman.',
                'restore_and_rollback_failed' => 'Restore dan pemulihan otomatis gagal. Backup pengaman masih tersimpan di server.',
                default => 'Restore gagal sebelum selesai. Periksa status database dan backup pengaman sebelum mencoba lagi.',
            };

            return response()->json(['code' => 'DATABASE_RESTORE_FAILED', 'message' => $message], 500);
        }

        Audit::log('database_backup.restored', $request->user(), null, [
            'filename' => $data['backup']->getClientOriginalName(),
            'ip' => $request->ip(),
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['restored' => true]]);
    }

    private function unsupportedResponse(): JsonResponse
    {
        return response()->json([
            'code' => 'DATABASE_BACKUP_UNSUPPORTED',
            'message' => 'Backup dan restore hanya tersedia saat aplikasi memakai MySQL.',
        ], 422);
    }
}

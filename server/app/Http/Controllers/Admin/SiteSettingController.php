<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SiteSettingController extends Controller
{
    public function attendance(): JsonResponse
    {
        Gate::authorize('admin');

        return response()->json(['data' => ['checkin_early_minutes' => SiteSetting::checkInEarlyMinutes()]]);
    }

    public function updateAttendance(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'checkin_early_minutes' => ['required', 'integer', 'between:0,720'],
        ]);

        $setting = SiteSetting::current();
        $before = $setting->attributesToArray();
        DB::transaction(function () use ($setting, $data, $before): void {
            $setting->fill($data)->save();
            Audit::log('attendance_settings.updated', $setting, $before, $setting->attributesToArray());
        });
        Cache::forget('attendance.checkin_early_minutes');

        return response()->json(['data' => ['checkin_early_minutes' => SiteSetting::checkInEarlyMinutes()]]);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => SiteSetting::current()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_address' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_favicon' => ['sometimes', 'boolean'],
        ]);

        $setting = SiteSetting::current();
        $before = $setting->attributesToArray();
        $stored = [];
        $replaced = [];

        try {
            foreach (['logo', 'favicon'] as $asset) {
                $pathField = "{$asset}_path";

                if ($request->hasFile($asset)) {
                    $replaced[] = $setting->{$pathField};
                    $data[$pathField] = $request->file($asset)->storePublicly('site-settings', 'public');
                    $stored[] = $data[$pathField];
                } elseif ($request->boolean("remove_{$asset}")) {
                    $replaced[] = $setting->{$pathField};
                    $data[$pathField] = null;
                }
            }

            unset($data['logo'], $data['favicon'], $data['remove_logo'], $data['remove_favicon']);

            DB::transaction(function () use ($setting, $data, $before) {
                $setting->fill($data)->save();
                Audit::log('site_settings.updated', $setting, $before, $setting->attributesToArray());
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete($stored);

            throw $error;
        }

        Storage::disk('public')->delete(array_filter(array_unique($replaced)));

        return response()->json(['data' => $setting->fresh()]);
    }
}

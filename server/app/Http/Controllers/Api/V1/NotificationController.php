<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->feed($request));
    }

    public function adminIndex(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json($this->feed($request, true));
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return response()->json($this->feed($request, $request->is('admin/api/*')));
    }

    private function feed(Request $request, bool $hideOvertimeRecommendations = false): array
    {
        $user = $request->user();
        $notifications = $user->notifications();
        $unreadNotifications = $user->unreadNotifications();

        if ($hideOvertimeRecommendations) {
            $excludeOvertimeRecommendations = fn ($query) => $query->where(function ($query) {
                $query->whereNull('data->kind')
                    ->orWhere('data->kind', '!=', 'overtime_recommendation');
            });

            $excludeOvertimeRecommendations($notifications);
            $excludeOvertimeRecommendations($unreadNotifications);
        }

        $items = $notifications->latest()->limit(100)->get()->map(fn ($item) => [
            'id' => $item->id,
            'type' => $item->data['kind'] ?? $item->type,
            'data' => $item->data,
            'read_at' => $item->read_at?->toISOString(),
            'created_at' => $item->created_at?->toISOString(),
        ]);

        return ['data' => $items, 'unread_count' => $unreadNotifications->count()];
    }
}

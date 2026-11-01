<?php

namespace App\Notifications\Channels;

use App\Models\DeviceRegistration;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpoPushChannel
{
    public function send(User $notifiable, WorkflowNotification $notification): void
    {
        $devices = DeviceRegistration::query()
            ->where('user_id', $notifiable->id)
            ->whereNull('revoked_at')
            ->whereNotNull('expo_push_token')
            ->get(['id', 'expo_push_token']);
        if ($devices->isEmpty()) {
            return;
        }

        $messages = $devices->map(fn (DeviceRegistration $device) => [
            'to' => $device->expo_push_token,
            'sound' => 'default',
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => $notification->pushData(),
        ])->all();

        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->post('https://exp.host/--/api/v2/push/send', $messages);
            if (! $response->successful()) {
                Log::warning('Expo push request failed.', ['status' => $response->status(), 'user_id' => $notifiable->id]);

                return;
            }

            $tickets = $response->json('data', []);
            if (isset($tickets['status'])) {
                $tickets = [$tickets];
            }
            foreach ($tickets as $index => $ticket) {
                if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' && isset($devices[$index])) {
                    $devices[$index]->update(['expo_push_token' => null]);
                }
            }
        } catch (Throwable $error) {
            Log::warning('Expo push request could not be sent.', ['user_id' => $notifiable->id, 'message' => $error->getMessage()]);
        }
    }
}

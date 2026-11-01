<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Notifications\Notification;

class WorkflowNotification extends Notification
{
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public array $context,
        public bool $sendPush = false,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->sendPush ? ['database', ExpoPushChannel::class] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'context' => $this->context,
        ];
    }

    /** @return array<string, mixed> */
    public function pushData(): array
    {
        return [
            'kind' => $this->kind,
            ...$this->context,
        ];
    }
}

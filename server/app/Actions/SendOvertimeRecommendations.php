<?php

namespace App\Actions;

use App\Support\WorkflowNotifier;
use App\Support\WorkTime;

class SendOvertimeRecommendations
{
    public function __construct(
        private FindOvertimeRecommendations $recommendations,
        private WorkflowNotifier $notifier,
    ) {}

    public function handle(): int
    {
        $now = WorkTime::now();
        $items = $this->recommendations->execute($now->addMinutes(119), $now->addMinutes(121));

        foreach ($items as $item) {
            $this->notifier->overtimeRecommendation($item);
        }

        return $items->count();
    }
}

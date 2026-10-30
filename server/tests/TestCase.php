<?php

namespace Tests;

use App\Models\EmployeeLevel;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function completeEmployeeAttrs(array $attrs = []): array
    {
        $levelId = EmployeeLevel::query()->value('id')
            ?? EmployeeLevel::query()->create(['code' => 'TEST', 'name' => 'Level tes'])->id;

        return array_merge([
            'work_location_id' => WorkLocation::query()->value('id'),
            'level_id' => $levelId,
        ], $attrs);
    }
}

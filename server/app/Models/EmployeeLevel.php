<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'requires_super_admin_approval'])]
class EmployeeLevel extends Model
{
    protected function casts(): array
    {
        return ['requires_super_admin_approval' => 'boolean'];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'level_id');
    }
}

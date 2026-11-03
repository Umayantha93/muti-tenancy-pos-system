<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['branch_id', 'payload'])]
class ClothingFloorState extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}

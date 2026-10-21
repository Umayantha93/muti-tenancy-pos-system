<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'email', 'phone', 'message', 'opened_at'])]
class ContactMessage extends Model
{
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
        ];
    }
}

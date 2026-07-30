<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['category', 'description', 'amount', 'expense_date', 'created_by', 'updated_by'])]
class Expense extends Model
{
    use BelongsToTenant;
    protected function casts(): array { return ['expense_date' => 'date', 'amount' => 'decimal:2']; }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}

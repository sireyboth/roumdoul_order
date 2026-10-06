<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash put into (in) or taken out of (out) the drawer that is not a sale. */
class CashMovement extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'shift_id', 'type', 'amount', 'currency', 'reason', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

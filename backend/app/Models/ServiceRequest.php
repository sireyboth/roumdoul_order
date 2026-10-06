<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Live;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Call waiter" or "Request bill" from a table. */
class ServiceRequest extends Model
{
    use BelongsToCompany;

    public const TYPES = ['waiter', 'bill'];

    protected $fillable = ['company_id', 'branch_id', 'dining_table_id', 'table_session_id', 'type', 'status', 'handled_by_user_id', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Staff screens and the customer phone update at once (Reverb); see App\Support\Live.
        static::saved(fn (self $request) => Live::changed($request->branch_id, $request->dining_table_id));
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }
}

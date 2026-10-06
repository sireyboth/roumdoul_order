<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
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

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }
}

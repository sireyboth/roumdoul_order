<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Live;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One visit: many orders, one bill. */
class TableSession extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'branch_id', 'dining_table_id', 'status', 'opened_at', 'bill_requested_at', 'closed_at'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'bill_requested_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Staff screens and the customer phone update at once (Reverb); see App\Support\Live.
        static::saved(fn (self $session) => Live::changed($session->branch_id, $session->dining_table_id));
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function bill(): HasOne
    {
        return $this->hasOne(Bill::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function isOpen(): bool
    {
        return $this->status !== 'closed';
    }
}

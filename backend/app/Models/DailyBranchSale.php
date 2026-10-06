<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One branch's sales for one business day. A report copy: rebuilt by App\Services\Reports\DailySales. */
class DailyBranchSale extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        $ints = array_fill_keys([
            'orders_count', 'cancelled_count', 'bills_count', 'items_count', 'gross', 'discounts',
            'service_charge', 'vat', 'net', 'refunds', 'cash', 'khqr', 'card', 'other',
        ], 'integer');

        return $ints + ['business_date' => 'date', 'summary_sent_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'order_id', 'menu_item_id', 'name_km', 'name_en', 'station',
        'unit_price', 'quantity', 'line_total', 'options', 'note',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\BumpsMenuVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Option extends Model
{
    use BelongsToCompany, BumpsMenuVersion;

    protected $fillable = ['company_id', 'option_group_id', 'name_km', 'name_en', 'price_delta', 'is_default', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'price_delta' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $option) {
            if (! $option->company_id && $option->option_group_id) {
                $option->company_id = OptionGroup::query()->whereKey($option->option_group_id)->value('company_id');
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }
}

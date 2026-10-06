<?php

namespace App\Models;

use App\Enums\Station;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\BumpsMenuVersion;
use App\Services\MenuSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MenuItem extends Model
{
    use Auditable, BelongsToCompany, BumpsMenuVersion, SoftDeletes;

    protected $fillable = [
        'company_id', 'category_id', 'name_km', 'name_en', 'name_zh', 'description_km', 'description_en',
        'image_path', 'price', 'sku', 'station', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'station' => Station::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A new item becomes available in every branch; owners switch it off per branch if needed.
        static::created(fn (self $item) => app(MenuSync::class)->ensureRowsForItem($item));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class)
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function branchSettings(): HasMany
    {
        return $this->hasMany(BranchMenuItem::class);
    }
}

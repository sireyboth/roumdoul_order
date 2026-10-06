<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\BumpsMenuVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** e.g. "Sugar level" (pick 1) or "Toppings" (pick up to 3). */
class OptionGroup extends Model
{
    use Auditable, BelongsToCompany, BumpsMenuVersion;

    protected $fillable = ['company_id', 'name_km', 'name_en', 'min_select', 'max_select', 'sort_order'];

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('sort_order');
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class);
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }
}

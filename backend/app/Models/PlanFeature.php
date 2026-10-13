<?php

namespace App\Models;

use App\Enums\Feature;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Admin's name and order for one App\Enums\Feature. Whether the feature is built is never
 * stored: it comes from the code (`built()` / `Feature::isBuilt()`).
 */
class PlanFeature extends Model
{
    use Auditable;

    protected $fillable = ['key', 'name', 'sort_order'];

    protected static function booted(): void
    {
        // A new feature goes to the end of the list (drag to reorder in /admin).
        static::creating(function (PlanFeature $feature) {
            $feature->sort_order ??= (int) static::query()->max('sort_order') + 1;
        });
    }

    public function feature(): ?Feature
    {
        return Feature::tryFrom($this->key);
    }

    public function built(): bool
    {
        return $this->feature()?->isBuilt() ?? false;
    }

    /** Only rows for features that exist in the code. */
    public function scopeKnown(Builder $query): void
    {
        $query->whereIn('key', array_column(Feature::cases(), 'value'));
    }

    /** Adds a row (default name, at the end) for every feature in the code that has none yet. */
    public static function syncWithCode(): void
    {
        $have = static::query()->pluck('key')->all();

        foreach (Feature::cases() as $feature) {
            if (! in_array($feature->value, $have, true)) {
                static::query()->create(['key' => $feature->value, 'name' => $feature->getLabel()]);
            }
        }
    }

    /**
     * Every feature in the code, in admin's order, keyed by key. Read-only: a feature with no row
     * yet gets an unsaved one with its default name, listed last.
     *
     * @return Collection<string, PlanFeature>
     */
    public static function catalog(): Collection
    {
        $rows = static::query()->known()->orderBy('sort_order')->orderBy('id')->get()->toBase()->keyBy('key');

        foreach (Feature::cases() as $feature) {
            $rows[$feature->value] ??= new static(['key' => $feature->value, 'name' => $feature->getLabel()]);
        }

        return $rows;
    }

    /** key => name, in order (options for the Plans form). */
    public static function options(): array
    {
        return static::catalog()->map(fn (PlanFeature $f) => $f->name)->all();
    }
}

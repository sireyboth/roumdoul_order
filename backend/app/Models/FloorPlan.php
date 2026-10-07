<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The drawn layout of one floor of a branch (tables, bar, walls, plants...),
 * shown on the staff screens. One plan per (branch, area); area null = "Main floor".
 * A dining table is placed on at most one floor of its branch.
 */
class FloorPlan extends Model
{
    use BelongsToCompany;

    public const FLOORS = ['wood', 'tile', 'stone', 'carpet', 'grass', 'concrete'];

    /** Kinds that stand for a real dining table (they carry table_id and seats). */
    public const TABLE_KINDS = ['table_square', 'table_round', 'table_long', 'booth'];

    public const KINDS = [
        'table_square', 'table_round', 'table_long', 'booth', 'bar_counter', 'cashier', 'kitchen',
        'wall', 'door', 'window', 'plant', 'pillar', 'rug', 'sofa', 'stairs', 'restroom', 'divider',
        'stool', 'chair', 'lamp',
    ];

    protected $fillable = ['company_id', 'branch_id', 'table_area_id', 'floor', 'width', 'height', 'objects'];

    protected function casts(): array
    {
        return [
            'objects' => 'array',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(TableArea::class, 'table_area_id');
    }

    /** The plan for a branch floor, or a new unsaved one. Area null = Main floor. */
    public static function forFloor(Branch $branch, ?int $areaId): self
    {
        $plan = self::query()
            ->where('branch_id', $branch->id)
            ->when($areaId, fn ($q) => $q->where('table_area_id', $areaId), fn ($q) => $q->whereNull('table_area_id'))
            ->latest('updated_at')
            ->latest('id')
            ->first();

        return $plan ?? new self([
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'table_area_id' => $areaId,
        ]);
    }
}

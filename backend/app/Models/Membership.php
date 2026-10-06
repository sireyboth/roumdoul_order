<?php

namespace App\Models;

use App\Enums\StaffRole;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** A user's job at one company (role, active, manager PIN). Table: company_user. */
class Membership extends Pivot
{
    use Auditable, BelongsToCompany;

    protected $table = 'company_user';

    public $incrementing = true;

    protected $fillable = ['company_id', 'user_id', 'role', 'pin_hash', 'is_active'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

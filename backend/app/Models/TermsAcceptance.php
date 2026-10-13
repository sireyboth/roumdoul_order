<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An owner agreed to the terms (version, when, for what). Written by App\Support\Terms, never changed. */
class TermsAcceptance extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'user_id', 'version', 'context', 'detail', 'ip', 'user_agent'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

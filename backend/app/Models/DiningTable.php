<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Services\MenuBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DiningTable extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'branch_id', 'table_area_id', 'name', 'seats', 'is_active', 'sort_order'];

    protected $hidden = ['qr_token'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $table) {
            $table->qr_token ??= self::newToken();

            // Branch decides the company, never the form.
            if ($table->branch_id && ! $table->company_id) {
                $table->company_id = Branch::query()->whereKey($table->branch_id)->value('company_id');
            }
        });

        // The cached token lookup must not outlive a change to the table.
        $forget = fn (self $table) => MenuBuilder::forgetToken($table->getOriginal('qr_token') ?? $table->qr_token);
        static::updated($forget);
        static::deleted($forget);
    }

    /** 24 random letters and digits: impossible to guess, short enough for a QR code. */
    public static function newToken(): string
    {
        return Str::random(24);
    }

    /** Old printed QR codes stop working immediately. */
    public function regenerateToken(): void
    {
        MenuBuilder::forgetToken($this->qr_token);
        $this->forceFill(['qr_token' => self::newToken()])->save();
    }

    public function customerUrl(): string
    {
        return config('tok.frontend_url').'/t/'.$this->qr_token;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    public function openSession(): ?TableSession
    {
        return $this->sessions()->where('status', '!=', 'closed')->latest('id')->first();
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(TableArea::class, 'table_area_id');
    }
}

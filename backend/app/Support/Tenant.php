<?php

namespace App\Support;

use App\Models\Company;
use Filament\Facades\Filament;

/** The company the current back-office user is working in (/app/{company}). */
final class Tenant
{
    public static function current(): ?Company
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company ? $tenant : null;
    }

    public static function id(): ?int
    {
        return self::current()?->getKey();
    }

    public static function currency(): string
    {
        return self::current()?->currency ?? 'USD';
    }
}

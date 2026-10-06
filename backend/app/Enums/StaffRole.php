<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StaffRole: string implements HasLabel
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Cashier = 'cashier';
    case Kitchen = 'kitchen';
    case Waiter = 'waiter';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Cashier => 'Cashier',
            self::Kitchen => 'Kitchen',
            self::Waiter => 'Waiter',
        };
    }

    /** Roles that may open the Filament back office (/app). Others use the staff screens. */
    public function canUseBackOffice(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }
}

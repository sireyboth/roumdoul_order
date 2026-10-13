<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** A restaurant's plan invoice (plan_invoices.status). Never deleted: unpaid ones are voided with a reason. */
enum InvoiceStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for payment',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Paid => 'success',
            self::Void => 'gray',
        };
    }
}

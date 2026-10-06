<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Which staff screen an item's ticket goes to. */
enum Station: string implements HasLabel
{
    case Kitchen = 'kitchen';
    case Bar = 'bar';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}

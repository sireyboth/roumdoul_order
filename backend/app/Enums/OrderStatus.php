<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** The kitchen track of an order. Payment is tracked separately (Step 1 part B). */
enum OrderStatus: string implements HasColor, HasLabel
{
    case Placed = 'placed';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Every allowed move. Anything else is rejected, so the history always makes sense. */
    public function canMoveTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Placed => [self::Accepted, self::Preparing, self::Cancelled],
            self::Accepted => [self::Preparing, self::Ready, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            self::Ready => [self::Served, self::Preparing],
            self::Served => [self::Completed],
            self::Completed, self::Cancelled => [],
        }, true);
    }

    /** Still needs attention from kitchen or waiters. */
    public function isActive(): bool
    {
        return in_array($this, [self::Placed, self::Accepted, self::Preparing, self::Ready], true);
    }

    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Accepted => 'accepted_at',
            self::Preparing => 'preparing_at',
            self::Ready => 'ready_at',
            self::Served => 'served_at',
            self::Completed => 'completed_at',
            self::Cancelled => 'cancelled_at',
            default => null,
        };
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Placed => 'warning',
            self::Accepted, self::Preparing => 'info',
            self::Ready => 'success',
            self::Served, self::Completed => 'gray',
            self::Cancelled => 'danger',
        };
    }
}

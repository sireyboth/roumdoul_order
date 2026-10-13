<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Every feature a plan can include. The code is the only place that says whether a feature
 * exists: when you finish one, move it to isBuilt() in the same change, and the start page
 * drops "Coming soon" on the next deploy. Names, order and which plans include it are set in
 * /admin (Plan features, Plans). A new case shows up there by itself.
 */
enum Feature: string implements HasLabel
{
    case Telegram = 'telegram';
    case Printing = 'printing';
    case Export = 'export';
    case Upsell = 'upsell';
    case KhqrAuto = 'khqr_auto';
    case Combos = 'combos';
    case Kiosk = 'kiosk';
    case MultiBranchReports = 'multi_branch_reports';

    public function isBuilt(): bool
    {
        return match ($this) {
            self::Telegram, self::Printing, self::Export, self::Upsell => true,
            // Step 2 (docs/step2-features.md).
            self::KhqrAuto, self::Combos, self::Kiosk, self::MultiBranchReports => false,
        };
    }

    /** Name until one is set in /admin > Plan features. */
    public function getLabel(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram day-end sales summary',
            self::Printing => 'Receipt and kitchen ticket printing',
            self::Export => 'Sales reports and CSV export',
            self::Upsell => '“Goes well with” suggestions',
            self::KhqrAuto => 'Automatic KHQR payment check',
            self::Combos => 'Combo and set menus',
            self::Kiosk => 'Self-order kiosk mode',
            self::MultiBranchReports => 'Compare branches side by side',
        };
    }
}

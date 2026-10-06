<?php

namespace App\Filament\App\Widgets;

use App\Support\Tenant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** First thing an owner sees: is the restaurant ready to take QR orders? */
class SetupOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        $company = Tenant::current();

        if (! $company) {
            return [];
        }

        $items = $company->menuItems()->where('is_active', true)->count();
        $tables = $company->diningTables()->where('is_active', true)->count();
        $branches = $company->branches()->where('is_active', true)->count();
        $plan = $company->subscription?->plan;

        $trial = $company->trial_ends_at && $company->status->value === 'trial'
            ? Stat::make('Free trial', max(0, (int) now()->diffInDays($company->trial_ends_at, false)).' days left')
                ->description('Ends '.$company->trial_ends_at->timezone($company->timezone)->format('d M Y'))
                ->color('info')
            : Stat::make('Plan', $plan?->name ?? '—')->description(ucfirst($company->status->value));

        return [
            Stat::make('Menu items', $items)
                ->description($items ? 'Shown to customers' : 'Add your first item under Menu')
                ->color($items ? 'success' : 'warning'),
            Stat::make('Tables with QR', $tables)
                ->description($tables ? 'Ready to print' : 'Add tables to get QR codes')
                ->color($tables ? 'success' : 'warning'),
            Stat::make('Branches open', $branches),
            $trial,
        ];
    }
}

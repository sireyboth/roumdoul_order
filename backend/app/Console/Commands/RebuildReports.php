<?php

namespace App\Console\Commands;

use App\Services\Reports\DailySales;
use Illuminate\Console\Command;

/** Recomputes the report copy from the raw orders, bills and payments. Safe to run any time. */
class RebuildReports extends Command
{
    protected $signature = 'reports:rebuild
        {date? : One business day (YYYY-MM-DD); leave out for every day with activity}
        {--from= : First business day}
        {--to= : Last business day}
        {--branch= : Only this branch id}';

    protected $description = 'Rebuild daily_branch_sales and daily_item_sales from orders, bills and payments';

    public function handle(DailySales $sales): int
    {
        $from = $this->argument('date') ?? $this->option('from');
        $to = $this->argument('date') ?? $this->option('to');

        foreach ([$from, $to] as $day) {
            if ($day !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $this->error("Dates look like 2026-10-07, not {$day}.");

                return self::INVALID;
            }
        }

        $days = DailySales::daysWithActivity($from, $to, $this->option('branch') ? (int) $this->option('branch') : null);

        foreach ($days as $day) {
            $row = $sales->rebuild($day['branch_id'], $day['date']);
            $this->line("Branch {$day['branch_id']} · {$day['date']}: {$row->bills_count} bills, net {$row->net}");
        }

        $this->info(count($days).' branch-day(s) rebuilt.');

        return self::SUCCESS;
    }
}

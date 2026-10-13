<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Plan billing housekeeping, scheduled hourly: makes the next period's invoices
 * (a few days before the period ends) and flags unpaid ones past the grace days
 * (back office read-only, Telegram to the Roumdoul team).
 */
class RunSubscriptions extends Command
{
    protected $signature = 'subscriptions:run';

    protected $description = 'Make due plan invoices and flag overdue ones';

    public function handle(SubscriptionService $subscriptions): int
    {
        $made = $subscriptions->issueDueInvoices();
        $late = $subscriptions->flagOverdue();

        $this->info("Invoices made: {$made}. Newly overdue: {$late}.");

        return self::SUCCESS;
    }
}

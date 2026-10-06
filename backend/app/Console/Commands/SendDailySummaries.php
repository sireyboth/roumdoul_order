<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\DailyBranchSale;
use App\Models\DailyItemSale;
use App\Services\Reports\DailySales;
use App\Services\TelegramNotifier;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * After each branch's business day ends (its "day ends at" time), sends the
 * day's sales to the restaurant's Telegram chat, once. Scheduled every 15 minutes.
 */
class SendDailySummaries extends Command
{
    protected $signature = 'reports:daily-summary';

    protected $description = 'Send the Telegram sales summary for branches whose business day has ended';

    public function handle(DailySales $sales, TelegramNotifier $telegram): int
    {
        $sent = 0;

        Branch::query()
            ->where('is_active', true)
            ->with('company')
            ->get()
            ->filter(fn (Branch $b) => $b->company && filled($b->company->telegram_chat_id))
            ->each(function (Branch $branch) use ($sales, $telegram, &$sent) {
                // The day before today's business date has fully ended.
                $day = Carbon::parse($branch->businessDate())->subDay()->toDateString();
                $row = $sales->rebuild($branch->id, $day);

                if ($row->summary_sent_at || ($row->orders_count === 0 && $row->bills_count === 0)) {
                    return;
                }

                $telegram->send($branch->company->telegram_chat_id, self::message($branch, $row));
                $row->forceFill(['summary_sent_at' => now()])->save();
                $sent++;
            });

        $this->info("{$sent} summary message(s) sent.");

        return self::SUCCESS;
    }

    public static function message(Branch $branch, DailyBranchSale $row): string
    {
        $esc = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $money = fn (int $minor) => Money::format($minor, $row->currency);
        $company = $branch->company;

        $top = DailyItemSale::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $row->business_date)
            ->orderByDesc('quantity')
            ->limit(5)
            ->get();

        $lines = [
            "📊 <b>Daily sales · {$esc($company->name)} · {$esc($branch->name)}</b>",
            $row->business_date->format('D d M Y'),
            '',
            '💰 Net sales: <b>'.$money($row->net).'</b>'.($row->currency === 'USD' ? ' ('.Money::format(Money::toRiel($row->net, 'USD', $company->khr_per_usd), 'KHR').')' : ''),
            "🧾 Bills: {$row->bills_count} · Orders: {$row->orders_count}".($row->bills_count ? ' · Average bill: '.$money(intdiv($row->net, $row->bills_count)) : ''),
            '💵 Cash '.$money($row->cash).' · KHQR '.$money($row->khqr).($row->card ? ' · Card '.$money($row->card) : '').($row->other ? ' · Other '.$money($row->other) : ''),
        ];

        if ($row->discounts || $row->refunds || $row->cancelled_count) {
            $lines[] = '⚠️ Discounts '.$money($row->discounts).' · Refunds '.$money($row->refunds).' · Cancelled orders '.$row->cancelled_count;
        }

        if ($top->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🏆 Best sellers';
            foreach ($top as $i => $item) {
                $lines[] = ($i + 1).". {$esc($item->name_en)} × {$item->quantity}";
            }
        }

        return implode("\n", $lines);
    }
}

<?php

namespace App\Services;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends restaurant alerts to the Telegram chat set in Restaurant settings.
 * Needs TELEGRAM_BOT_TOKEN in .env; silently does nothing without it.
 */
class TelegramNotifier
{
    public function newOrder(Order $order): void
    {
        $order->loadMissing(['items', 'table', 'branch.company']);
        $company = $order->branch->company;

        if (! $company->telegram_chat_id) {
            return;
        }

        $esc = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES);

        $lines = [
            "🧾 <b>New order #{$order->number}</b> · Table {$esc($order->table?->name)} · {$esc($order->branch->name)}",
            '',
        ];

        foreach ($order->items as $item) {
            $options = collect($item->options ?? [])->pluck('name_en')->implode(', ');
            $lines[] = "{$item->quantity} × {$esc($item->name_en)}".($options ? " ({$esc($options)})" : '');
            if ($item->note) {
                $lines[] = '   📝 '.$esc($item->note);
            }
        }

        $lines[] = '';
        $lines[] = '💰 '.Money::format($order->subtotal, $order->currency);

        $this->send($company->telegram_chat_id, implode("\n", $lines));
    }

    public function send(string $chatId, string $html): void
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            return;
        }

        try {
            Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
            ])->throw();
        } catch (\Throwable $e) {
            Log::warning('Telegram alert failed: '.$e->getMessage());
        }
    }
}

<?php

namespace App\Services\Ordering;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceRequest;

/** JSON shapes for orders, shared by the customer and staff APIs. */
class OrderPresenter
{
    /** @return array<string, mixed> */
    public static function order(Order $order, bool $forStaff = false): array
    {
        $data = [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'currency' => $order->currency,
            'subtotal' => $order->subtotal,
            'note' => $order->note,
            'placed_at' => $order->created_at?->toIso8601String(),
            'ready_at' => $order->ready_at?->toIso8601String(),
            'items' => $order->items->map(fn (OrderItem $item) => self::item($item, $forStaff))->values()->all(),
        ];

        if ($forStaff) {
            $data += [
                'table' => $order->table?->name,
                'area' => $order->table?->area?->name,
                'source' => $order->source,
                'accepted_at' => $order->accepted_at?->toIso8601String(),
                'preparing_at' => $order->preparing_at?->toIso8601String(),
                'served_at' => $order->served_at?->toIso8601String(),
                'cancel_reason' => $order->cancel_reason,
            ];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function item(OrderItem $item, bool $forStaff = false): array
    {
        return [
            'name' => ['km' => $item->name_km, 'en' => $item->name_en],
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'line_total' => $item->line_total,
            'options' => collect($item->options ?? [])->map(fn ($o) => ['km' => $o['name_km'], 'en' => $o['name_en']])->all(),
            'note' => $item->note,
        ] + ($forStaff ? ['station' => $item->station, 'menu_item_id' => $item->menu_item_id] : []);
    }

    /** @return array<string, mixed> */
    public static function request(ServiceRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->type,
            'status' => $request->status,
            'table' => $request->table?->name,
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }
}

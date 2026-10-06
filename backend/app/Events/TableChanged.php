<?php

namespace App\Events;

use App\Support\Live;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "Your table changed" for the customer's phone (order status, bill, paid).
 * The channel name is a secret hash only handed out with the table's QR token,
 * and the event carries no data: the phone re-reads its session.
 */
class TableChanged implements ShouldBroadcastNow
{
    public function __construct(public int $tableId) {}

    public function broadcastOn(): Channel
    {
        return new Channel(Live::tableChannel($this->tableId));
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }
}

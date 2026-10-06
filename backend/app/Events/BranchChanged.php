<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "Something changed at this branch" for the staff screens. Carries no data:
 * screens re-fetch through the normal, permission-checked API.
 */
class BranchChanged implements ShouldBroadcastNow
{
    public function __construct(public int $branchId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('branch.'.$this->branchId);
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

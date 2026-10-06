<?php

namespace App\Jobs;

use App\Services\Reports\DailySales;
use Illuminate\Foundation\Bus\Dispatchable;

/** Recomputes one branch-day of the report copy. Dispatched after the response, so no queue worker is needed. */
class RebuildDailySales
{
    use Dispatchable;

    public function __construct(public int $branchId, public string $date) {}

    public function handle(DailySales $sales): void
    {
        $sales->rebuild($this->branchId, $this->date);
    }
}

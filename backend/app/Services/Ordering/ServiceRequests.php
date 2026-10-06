<?php

namespace App\Services\Ordering;

use App\Models\DiningTable;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ServiceRequests
{
    /** A second tap while the first is still open returns the same request (no spam for staff). */
    public function open(DiningTable $table, string $type): ServiceRequest
    {
        return DB::transaction(function () use ($table, $type) {
            DiningTable::query()->whereKey($table->id)->lockForUpdate()->first();

            $session = $table->openSession();

            $existing = ServiceRequest::query()
                ->where('dining_table_id', $table->id)
                ->where('type', $type)
                ->where('status', 'open')
                ->first();

            if ($type === 'bill' && $session && $session->status === 'open') {
                $session->update(['status' => 'bill_requested', 'bill_requested_at' => now()]);
            }

            return $existing ?? ServiceRequest::query()->create([
                'company_id' => $table->company_id,
                'branch_id' => $table->branch_id,
                'dining_table_id' => $table->id,
                'table_session_id' => $session?->id,
                'type' => $type,
                'status' => 'open',
            ]);
        });
    }

    public function done(ServiceRequest $request, User $by): void
    {
        if ($request->status === 'done') {
            return;
        }

        $request->update(['status' => 'done', 'handled_by_user_id' => $by->id, 'handled_at' => now()]);
    }
}

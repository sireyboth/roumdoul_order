<?php

namespace App\Filament\Admin\Resources\PlanInvoices\Pages;

use App\Filament\Admin\Resources\PlanInvoices\PlanInvoiceResource;
use Filament\Resources\Pages\ListRecords;

/** No "New" button: invoices come from plan changes and renewals (SubscriptionService). */
class ListPlanInvoices extends ListRecords
{
    protected static string $resource = PlanInvoiceResource::class;
}

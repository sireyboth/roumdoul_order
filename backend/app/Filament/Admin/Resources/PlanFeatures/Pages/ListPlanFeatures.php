<?php

namespace App\Filament\Admin\Resources\PlanFeatures\Pages;

use App\Filament\Admin\Resources\PlanFeatures\PlanFeatureResource;
use App\Models\PlanFeature;
use Filament\Resources\Pages\ListRecords;

/** No "New" button: features are added in the code (App\Enums\Feature) and appear here by themselves. */
class ListPlanFeatures extends ListRecords
{
    protected static string $resource = PlanFeatureResource::class;

    public function mount(): void
    {
        PlanFeature::syncWithCode();

        parent::mount();
    }
}

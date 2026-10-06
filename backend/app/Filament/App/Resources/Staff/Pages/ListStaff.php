<?php

namespace App\Filament\App\Resources\Staff\Pages;

use App\Filament\App\Resources\Staff\StaffResource;
use App\Models\Membership;
use App\Support\Tenant;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add staff')
                ->using(function (array $data, CreateAction $action) {
                    $alreadyMember = Membership::query()
                        ->where('company_id', Tenant::id())
                        ->whereHas('user', fn ($q) => $q->where('email', strtolower($data['email'])))
                        ->exists();

                    if ($alreadyMember) {
                        Notification::make()->title('This person is already on your staff list.')->danger()->send();
                        $action->halt();
                    }

                    return StaffResource::createMember($data);
                }),
        ];
    }
}

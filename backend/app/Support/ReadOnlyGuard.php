<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\PlanInvoice;
use App\Models\Subscription;
use App\Models\TermsAcceptance;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * When a restaurant's plan payment is overdue (Company::isReadOnly), nothing can be saved or
 * deleted in its back office (/app), except paying: the "Your plan" page still works.
 * Only applies inside /app (a Filament tenant is set): staff screens, customer orders and
 * /admin keep working, so the shop never stops serving guests.
 */
final class ReadOnlyGuard
{
    /** Writes still allowed while read-only: plan changes, invoices, sign-in, the audit trail. */
    private const ALLOWED = [PlanInvoice::class, Subscription::class, TermsAcceptance::class, User::class, AuditLog::class];

    public static function register(): void
    {
        Event::listen(['eloquent.saving: *', 'eloquent.deleting: *'], function (string $event, array $payload) {
            $model = $payload[0] ?? null;

            if (! $model instanceof Model || in_array($model::class, self::ALLOWED, true)) {
                return;
            }

            $company = Tenant::current();

            if (! $company?->isReadOnly()) {
                return;
            }

            Notification::make()
                ->title('Read-only: plan payment overdue')
                ->body('Nothing can be changed until the payment is made. Orders and staff screens still work. Pay on the "Your plan" page.')
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        });
    }
}

@php
    /** Top of every back-office page: plan payment due soon, or late (read-only). */
    $company = \App\Support\Tenant::current();
    $late = $company?->overdueInvoice();
    $due = $late ? null : $company?->planInvoices()->pending()->where('kind', '!=', 'upgrade')->orderBy('due_at')->first();
    $isOwner = $company && auth()->user()?->roleIn($company) === \App\Enums\StaffRole::Owner;
    $url = $isOwner ? \App\Filament\App\Pages\YourPlan::getUrl() : null;
@endphp

@if ($late || $due)
    {{-- Filament's own colour variables, so it follows light/dark without extra CSS. --}}
    <style>
        .ro-billing { margin: 1rem 1rem 0; border-radius: .75rem; padding: .75rem 1rem; font-size: .875rem; line-height: 1.4; }
        .ro-billing--late { background: var(--danger-50); color: var(--danger-800); box-shadow: inset 0 0 0 1px var(--danger-200); }
        .ro-billing--due { background: var(--warning-50); color: var(--warning-800); box-shadow: inset 0 0 0 1px var(--warning-200); }
        .dark .ro-billing--late { background: var(--danger-950); color: var(--danger-200); box-shadow: inset 0 0 0 1px var(--danger-800); }
        .dark .ro-billing--due { background: var(--warning-950); color: var(--warning-200); box-shadow: inset 0 0 0 1px var(--warning-800); }
        .ro-billing a { font-weight: 600; text-decoration: underline; margin-left: .25rem; }
        @media (min-width: 640px) { .ro-billing { margin: 1rem 1.5rem 0; } }
        @media (min-width: 1024px) { .ro-billing { margin: 1rem 2rem 0; } }
    </style>
    <div class="ro-billing {{ $late ? 'ro-billing--late' : 'ro-billing--due' }}" role="status">
        @if ($late)
            <strong>Read-only: your plan payment of {{ $late->amount() }} was due {{ $late->due_at->format('j M Y') }}.</strong>
            Nothing can be changed here until it is paid. Orders and staff screens still work.
        @else
            <strong>Plan payment of {{ $due->amount() }} due {{ $due->due_at->format('j M Y') }}.</strong>
            @if ($due->proof_sent_at)
                Thank you, we are checking your payment.
            @else
                Please pay to keep everything running.
            @endif
        @endif

        @if ($url)
            <a href="{{ $url }}">Pay on "Your plan"</a>
        @elseif ($late)
            Please ask the owner to pay.
        @endif
    </div>
@endif

<?php

return [
    // The customer-facing Next.js site. QR codes point to {frontend_url}/t/{qr_token}.
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    // How long a built branch menu stays in cache. Any menu change bumps a
    // version number, so a stale menu is never served even before this expires.
    'menu_cache_ttl' => (int) env('MENU_CACHE_TTL', 86400),

    // Plan billing (docs/roadmap.md C2): the renewal invoice is made this many days before the
    // period ends, and an unpaid one turns the back office read-only this many days after.
    'invoice_days_before' => (int) env('INVOICE_DAYS_BEFORE', 3),
    'billing_grace_days' => (int) env('BILLING_GRACE_DAYS', 3),

    // Version (date) of the restaurant terms at {frontend_url}/terms. Must match TERMS_VERSION in
    // frontend/src/lib/terms.ts; owners agree again to a new version at their next plan change.
    'terms_version' => env('TERMS_VERSION', '2026-10-13'),
];

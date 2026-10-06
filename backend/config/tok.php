<?php

return [
    // The customer-facing Next.js site. QR codes point to {frontend_url}/t/{qr_token}.
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    // How long a built branch menu stays in cache. Any menu change bumps a
    // version number, so a stale menu is never served even before this expires.
    'menu_cache_ttl' => (int) env('MENU_CACHE_TTL', 86400),
];

<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Where uploaded pictures (logos, covers, KHQR, menu photos) are stored.
 * Local dev: the "public" disk (needs `php artisan storage:link`).
 * Laravel Cloud: a bucket (UPLOADS_DISK=<bucket disk name>), because the
 * server's own disk is wiped on every deploy. The bucket must be public.
 */
class Uploads
{
    public static function disk(): string
    {
        return config('filesystems.uploads');
    }

    public static function url(?string $path): ?string
    {
        return $path ? Storage::disk(self::disk())->url($path) : null;
    }
}

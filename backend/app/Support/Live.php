<?php

namespace App\Support;

use App\Events\BranchChanged;
use App\Events\TableChanged;
use Illuminate\Support\Facades\Log;

/**
 * Instant updates (Laravel Reverb). Collects what changed during a request and
 * broadcasts it once when the request ends (after every transaction and after the
 * response is sent; a rolled-back change only makes a screen re-fetch, which is harmless).
 * If Reverb is not configured or not running nothing breaks: the screens keep
 * polling as before.
 */
final class Live
{
    /** @var array<string, array{0:string,1:int}> */
    private static array $pending = [];

    private static bool $flushRegistered = false;

    public static function enabled(): bool
    {
        return in_array(config('broadcasting.default'), ['reverb', 'pusher'], true);
    }

    /** Secret, stable channel name for one table: only someone with its QR token is told it. */
    public static function tableChannel(int $tableId): string
    {
        return 'table.'.substr(hash_hmac('sha256', 'table-live|'.$tableId, (string) config('app.key')), 0, 32);
    }

    public static function changed(?int $branchId, ?int $tableId = null): void
    {
        if (! self::enabled()) {
            return;
        }

        if ($branchId) {
            self::$pending['b'.$branchId] = ['branch', $branchId];
        }

        if ($tableId) {
            self::$pending['t'.$tableId] = ['table', $tableId];
        }

        if (! self::$flushRegistered) {
            self::$flushRegistered = true;
            app()->terminating(fn () => self::flush());
        }
    }

    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];
        self::$flushRegistered = false;

        foreach ($pending as [$type, $id]) {
            try {
                broadcast($type === 'branch' ? new BranchChanged($id) : new TableChanged($id));
            } catch (\Throwable $e) {
                Log::debug('Live update not sent (is Reverb running?): '.$e->getMessage());
            }
        }
    }
}

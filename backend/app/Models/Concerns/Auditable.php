<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;
use Illuminate\Support\Str;

/**
 * Records create/update/delete of the model in audit_logs automatically, so
 * Filament screens don't have to remember to log anything themselves.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            AuditLogger::record(static::auditEventName('created'), $model, $model->getAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if (empty($changes)) {
                return;
            }

            $before = collect($model->getOriginal())->only(array_keys($changes))->all();

            AuditLogger::record(static::auditEventName('updated'), $model, $changes, $before);
        });

        static::deleted(function ($model) {
            AuditLogger::record(static::auditEventName('deleted'), $model, [], $model->getAttributes());
        });
    }

    protected static function auditEventName(string $action): string
    {
        return Str::snake(class_basename(static::class)).'.'.$action;
    }
}

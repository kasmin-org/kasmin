<?php

namespace App\Models\Concerns;

use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs every create, update and delete of the model in the audit log.
 * Attributes listed in $auditRedacted are logged only as "changed", never with their values.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            Audit::record(class_basename($model).'.created', $model, self::auditValues($model, array_map('strval', array_keys($model->getAttributes()))));
        });
        static::updated(function (Model $model) {
            $dirty = array_values(array_diff(array_keys($model->getChanges()), ['updated_at']));
            if ($dirty !== []) {
                Audit::record(class_basename($model).'.updated', $model, self::auditValues($model, $dirty, withOld: true));
            }
        });
        static::deleted(function (Model $model) {
            Audit::record(class_basename($model).'.deleted', $model);
        });
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function auditValues(Model $model, array $keys, bool $withOld = false): array
    {
        /** @var list<string> $redacted */
        $redacted = property_exists($model, 'auditRedacted') ? $model->auditRedacted : [];
        $hidden = $model->getHidden();
        $values = [];
        foreach ($keys as $key) {
            if (in_array($key, ['created_at', 'updated_at'], true)) {
                continue;
            }
            if (in_array($key, $redacted, true) || in_array($key, $hidden, true)) {
                $values[$key] = '[changed]';

                continue;
            }
            $values[$key] = $withOld ? ['old' => $model->getOriginal($key), 'new' => $model->getAttribute($key)] : $model->getAttribute($key);
        }

        return $values;
    }
}

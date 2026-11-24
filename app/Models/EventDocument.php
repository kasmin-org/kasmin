<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Document of an event, e.g. a packing list (EE01). The file is private; downloads are checked.
 *
 * @property int $id
 * @property int $event_id
 * @property string $title
 * @property string $path
 * @property string $visibility
 */
class EventDocument extends Model
{
    use Auditable;

    public const DIRECTORY = 'event-documents';

    protected $guarded = ['id'];

    protected $attributes = ['visibility' => 'participants'];

    protected static function booted(): void
    {
        static::deleted(function (EventDocument $document): void {
            if (! self::query()->where('path', $document->path)->exists()) {
                Storage::disk('local')->delete($document->path); // copies of an event share the file
            }
        });
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}

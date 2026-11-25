<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Webhooks\Webhooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property list<string> $events
 * @property string $secret
 * @property bool $active
 */
class Webhook extends Model
{
    use Auditable;

    /** Events that can be subscribed (payloads never contain confidential data). */
    public const EVENTS = [
        'participation.created', 'participation.status_changed', 'person.created', 'invoice.issued', 'invoice.paid',
        'payment.received', 'form.confirmed', 'automation.approval_requested', 'automation.run_finished', 'import.finished',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted', 'active' => 'boolean'];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    protected static function booted(): void
    {
        static::saved(fn () => Webhooks::flush());
        static::deleted(fn () => Webhooks::flush());
    }

    public function wants(string $event): bool
    {
        return $this->active && (in_array($event, $this->events, true) || in_array('*', $this->events, true));
    }
}

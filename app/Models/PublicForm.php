<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Form on a public address (PF01): register for an event, apply for the team, or stay informed.
 *
 * @property int $id
 * @property string $slug
 * @property string $kind
 * @property array<string, string> $title
 * @property array<string, string>|null $intro
 * @property int|null $event_id
 * @property string|null $role_key
 * @property int|null $group_id
 * @property int|null $membership_type_id
 * @property bool $needs_approval
 * @property Carbon|null $opens_at
 * @property Carbon|null $closes_at
 * @property bool $active
 */
class PublicForm extends Model
{
    use Auditable;

    public const KINDS = ['event', 'application', 'interest'];

    protected $guarded = ['id'];

    protected $attributes = ['needs_approval' => true, 'active' => false];

    protected function casts(): array
    {
        return ['title' => 'array', 'intro' => 'array', 'needs_approval' => 'boolean', 'active' => 'boolean', 'opens_at' => 'datetime', 'closes_at' => 'datetime'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function titleText(): string
    {
        return $this->title[app()->getLocale()] ?? $this->title['en'] ?? $this->slug;
    }

    public function introText(): ?string
    {
        return $this->intro[app()->getLocale()] ?? $this->intro['en'] ?? null;
    }

    /** open, early or closed (PF04) */
    public function state(): string
    {
        if ($this->opens_at !== null && $this->opens_at->isFuture()) {
            return 'early';
        }
        if (($this->closes_at !== null && $this->closes_at->isPast()) || ($this->kind === 'event' && $this->event?->isOpenForRegistration() !== true)) {
            return 'closed';
        }

        return 'open';
    }
}

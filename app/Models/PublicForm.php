<?php

namespace App\Models;

use App\Casts\OneText;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Form on a public address (PF01): register for an event, apply for the team, or stay informed.
 *
 * @property int $id
 * @property string $slug
 * @property string $kind
 * @property string|null $title
 * @property string|null $intro
 * @property int|null $event_id
 * @property string|null $role_key
 * @property int|null $group_id
 * @property int|null $membership_type_id
 * @property bool $needs_approval
 * @property bool $ask_newsletter
 * @property Carbon|null $opens_at
 * @property Carbon|null $closes_at
 * @property bool $active
 * @property bool $on_start_page
 * @property list<string>|null $old_slugs
 */
class PublicForm extends Model
{
    use Auditable;

    public const KINDS = ['event', 'application', 'interest', 'signup'];

    /** The active sign-up form for family accounts (B16), the open one first. */
    public static function signUp(): ?self
    {
        return self::query()->where('kind', 'signup')->where('active', true)->orderBy('id')->get()
            ->sortBy(fn (self $form) => $form->state() === 'open' ? 0 : 1)->first();
    }

    /**
     * Forms shown on the start page: active, open and marked for it (the sign-up form is the button there).
     *
     * @return Collection<int, self>
     */
    public static function onStartPage(): Collection
    {
        return self::query()->where('active', true)->where('on_start_page', true)->where('kind', '!=', 'signup')->with('event')->orderBy('id')->get()
            ->filter(fn (self $form) => $form->state() === 'open')->values();
    }

    protected $guarded = ['id'];

    protected $attributes = ['needs_approval' => true, 'active' => false, 'ask_newsletter' => false, 'on_start_page' => false];

    protected function casts(): array
    {
        return ['title' => OneText::class, 'intro' => OneText::class, 'needs_approval' => 'boolean', 'ask_newsletter' => 'boolean', 'active' => 'boolean', 'on_start_page' => 'boolean', 'opens_at' => 'datetime', 'closes_at' => 'datetime', 'old_slugs' => 'array'];
    }

    /**
     * The part of the address as it is saved (B15): trimmed, lower case, umlauts written out (ä → ae, ß → ss), other
     * accents removed, everything else a single hyphen, no hyphen at the ends; only a–z, 0–9 and "-", at most 64.
     */
    public static function slugFrom(?string $value): string
    {
        return trim(substr(Str::slug(trim((string) $value), '-', 'de'), 0, 64), '-');
    }

    /** Whether another form already has this address (now or as an earlier one that still forwards). */
    public static function slugTaken(string $slug, ?int $except = null): bool
    {
        return self::query()->when($except !== null, fn ($q) => $q->whereKeyNot($except))->where('slug', $slug)->exists();
    }

    public function url(): string
    {
        return route('public.form', $this->slug);
    }

    protected static function booted(): void
    {
        // Always save the normalised address; an earlier address keeps forwarding to the form (links on websites).
        static::saving(function (PublicForm $form) {
            $form->slug = self::slugFrom($form->slug);
            $old = (string) $form->getOriginal('slug');
            if ($form->exists && $form->isDirty('slug') && $old !== '' && $old !== $form->slug) {
                $form->old_slugs = array_values(array_unique([...($form->old_slugs ?? []), $old]));
            }
        });
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
        return $this->title ?: $this->slug;
    }

    public function introText(): ?string
    {
        return $this->intro ?: null;
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

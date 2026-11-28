<?php

namespace App\Models;

use App\Support\Auth\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property string $topic
 * @property string $frequency
 * @property Carbon|null $last_sent_at
 */
class NotificationSubscription extends Model
{
    public const TOPICS = ['registrations', 'profile_updates', 'safety_updates', 'waitlist', 'payments', 'bounces', 'approvals', 'deletion_requests', 'automation_errors', 'tasks', 'expense_claims'];

    public const FREQUENCIES = ['immediate', 'daily', 'weekly', 'off'];

    /** Right somebody needs to get a topic; checked again whenever something is sent. */
    public const AREAS = [
        'registrations' => 'events', 'profile_updates' => 'people', 'safety_updates' => 'safety', 'waitlist' => 'events', 'payments' => 'finance',
        'bounces' => 'mailings', 'approvals' => 'automations', 'deletion_requests' => 'privacy', 'automation_errors' => 'automations', 'tasks' => 'automations', 'expense_claims' => 'finance',
    ];

    /** For administrators who have not chosen anything: nobody should miss a deletion request or a failed automation. */
    public const ADMINISTRATOR_DEFAULTS = ['deletion_requests' => 'immediate', 'automation_errors' => 'daily'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }

    /** @return list<string> topics the user may get */
    public static function topicsFor(User $user): array
    {
        return array_values(array_filter(self::TOPICS, fn (string $topic) => Permissions::allows($user, self::AREAS[$topic])));
    }

    /** Administrators get the most important topics until they choose something else. */
    public static function ensureDefaults(): void
    {
        foreach (User::query()->whereIn('person_id', Permissions::administrators())->pluck('id') as $id) {
            foreach (self::ADMINISTRATOR_DEFAULTS as $topic => $frequency) {
                self::query()->firstOrCreate(['user_id' => $id, 'topic' => $topic], ['frequency' => $frequency, 'last_sent_at' => now()]);
            }
        }
    }

    public function mayReceive(): bool
    {
        return $this->user instanceof User && $this->user->person !== null && $this->user->person->getAttribute('anonymised_at') === null
            && Permissions::allows($this->user, self::AREAS[$this->topic] ?? '');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Filament\Support;

use App\Models\GroupWaitlistEntry;
use App\Models\Participation;
use App\Support\Audit\Audit;
use App\Support\Events\Waitlist;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Manual actions on waitlists of events and groups: priority flags, skip with reason (AD31, WL02).
 */
class WaitlistActions
{
    /**
     * @param  \Closure(): bool  $mayEdit
     * @return list<Action>
     */
    public static function make(\Closure $mayEdit): array
    {
        $waiting = fn (Participation|GroupWaitlistEntry $record) => $mayEdit() && in_array($record->status, ['waitlist', 'waiting'], true);

        return [
            Action::make('flags')->label(__('events.waitlist_flags_title'))->icon('heroicon-o-flag')->visible($waiting)
                ->fillForm(fn (Participation|GroupWaitlistEntry $record) => ['flags' => $record->waitlist_flags ?? [], 'note' => $record->waitlist_note])
                ->schema([
                    CheckboxList::make('flags')->label(__('events.waitlist_flags_title'))->options(__('events.waitlist_flags')),
                    TextInput::make('note')->label(__('events.waitlist_note'))->maxLength(250)->helperText(__('events.waitlist_note_hint')),
                ])
                ->action(function (Participation|GroupWaitlistEntry $record, array $data) {
                    $record->fill(['waitlist_flags' => array_values(array_intersect(Waitlist::FLAGS, (array) $data['flags'])) ?: null, 'waitlist_note' => $data['note']])->save();
                }),
            Action::make('skip')->label(__('events.waitlist_skip'))->icon('heroicon-o-forward')
                ->visible(fn (Participation|GroupWaitlistEntry $record) => $waiting($record) && $record->skipped_at === null)
                ->modalDescription(__('events.waitlist_skip_hint'))
                ->schema([Textarea::make('reason')->label(__('admin.finance.reason'))->required()->maxLength(250)])
                ->action(function (Participation|GroupWaitlistEntry $record, array $data) {
                    $record->fill(['skipped_at' => now(), 'skip_reason' => $data['reason']])->save();
                    Audit::record('waitlist.skipped', $record, null, ['reason' => $data['reason']]);
                }),
            Action::make('unskip')->label(__('events.waitlist_unskip'))->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (Participation|GroupWaitlistEntry $record) => $waiting($record) && $record->skipped_at !== null)
                ->action(fn (Participation|GroupWaitlistEntry $record) => $record->fill(['skipped_at' => null, 'skip_reason' => null])->save()),
        ];
    }

    /** @param  array{flags: list<string>, previous: bool, camps: int}|null  $place */
    public static function score(?array $place): string
    {
        if ($place === null) {
            return '';
        }
        $parts = array_map(fn (string $f) => (string) __('events.waitlist_flags.'.$f), $place['flags']);
        if ($place['previous']) {
            $parts[] = (string) __('events.waitlist_criteria.previous');
        }
        if ($place['camps'] > 0) {
            $parts[] = trans_choice('events.waitlist_camps', $place['camps'], ['count' => $place['camps']]);
        }

        return implode(' · ', $parts);
    }
}

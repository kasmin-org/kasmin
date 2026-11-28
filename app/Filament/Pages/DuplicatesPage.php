<?php

namespace App\Filament\Pages;

use App\Filament\Support\InArea;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\People\Duplicates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * Probable duplicates: merge with a choice per field, or mark as different people (PE10, AD23).
 */
class DuplicatesPage extends Page
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $slug = 'duplicates';

    protected string $view = 'filament.pages.duplicates';

    public static function getNavigationLabel(): string
    {
        return __('people.duplicates');
    }

    public function getTitle(): string
    {
        return __('people.duplicates');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Permissions::allows($user, 'people', 'edit');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['pairs' => app(Duplicates::class)->all()];
    }

    public function dismissAction(): Action
    {
        return Action::make('dismiss')->label(__('people.different'))->color('gray')->size('sm')->requiresConfirmation()
            ->action(fn (array $arguments) => app(Duplicates::class)->dismiss($this->person($arguments, 'first'), $this->person($arguments, 'second')));
    }

    public function mergeAction(): Action
    {
        return Action::make('merge')->label(__('people.merge'))->size('sm')->modalDescription(__('people.merge_hint'))
            ->schema(function (array $arguments) {
                $first = $this->person($arguments, 'first');
                $second = $this->person($arguments, 'second');
                $fields = [Radio::make('keep')->label(__('people.keep'))->required()->default('first')
                    ->options(['first' => $first->fullName().' (#'.$first->id.')', 'second' => $second->fullName().' (#'.$second->id.')'])];
                foreach (Duplicates::FIELDS as $field) {
                    [$a, $b] = [$this->text($first, $field), $this->text($second, $field)];
                    if ($a !== $b) {
                        $fields[] = Radio::make('field_'.$field)->label(__('admin.person.'.$field))->required()->default($a === '–' ? 'second' : 'first')->options(['first' => $a, 'second' => $b]);
                    }
                }

                return $fields;
            })
            ->action(function (array $data, array $arguments) {
                $people = ['first' => $this->person($arguments, 'first'), 'second' => $this->person($arguments, 'second')];
                $keep = $data['keep'] === 'second' ? 'second' : 'first';
                $fields = [];
                foreach (Duplicates::FIELDS as $field) {
                    $choice = $data['field_'.$field] ?? null;
                    if ($choice !== null) {
                        $fields[$field] = $people[$choice === 'second' ? 'second' : 'first']->getAttribute($field);
                    }
                }
                try {
                    app(Duplicates::class)->merge($people[$keep], $people[$keep === 'first' ? 'second' : 'first'], $fields);
                    Notification::make()->title(__('people.merged'))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    /** @param array<string, mixed> $arguments */
    private function person(array $arguments, string $key): Person
    {
        return Person::query()->whereNull('anonymised_at')->whereKey((int) ($arguments[$key] ?? 0))->firstOrFail();
    }

    private function text(Person $person, string $field): string
    {
        return $field === 'birth_date' ? ($person->birth_date?->isoFormat('L') ?? '–') : ((string) $person->getAttribute($field) ?: '–');
    }
}

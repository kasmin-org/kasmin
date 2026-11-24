<?php

namespace App\Filament\Resources\PublicSubmissions;

use App\Filament\Resources\PublicForms\PublicFormResource;
use App\Filament\Resources\PublicSubmissions\Pages\ListPublicSubmissions;
use App\Models\FormField;
use App\Models\PublicSubmission;
use App\Models\User;
use App\Support\Events\PublicForms;
use App\Support\Forms\FormValue;
use App\Support\Lists\EventLists;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sent public forms: decide about applications (PA04a). Unconfirmed ones are not shown.
 */
class PublicSubmissionResource extends Resource
{
    protected static ?string $model = PublicSubmission::class;

    protected static ?string $slug = 'public-submissions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('public.submission');
    }

    public static function getPluralModelLabel(): string
    {
        return __('public.submissions');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.forms');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = PublicSubmission::query()->where('status', 'pending')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function canViewAny(): bool
    {
        return PublicFormResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<PublicSubmission> */
    public static function getEloquentQuery(): Builder
    {
        return PublicSubmission::query()->where('status', '!=', 'unconfirmed')->with('publicForm', 'person');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label(__('lists.date'))->dateTime(),
            TextColumn::make('publicForm.slug')->label(__('public.form'))->formatStateUsing(fn (PublicSubmission $record) => $record->publicForm?->titleText()),
            TextColumn::make('name')->label(__('lists.name'))->state(fn (PublicSubmission $record) => $record->person?->fullName() ?? trim(($record->data['first_name'] ?? '').' '.($record->data['last_name'] ?? '')))->placeholder('–'),
            TextColumn::make('status')->label(__('admin.event.status'))->badge()->formatStateUsing(fn (string $state) => __('public.statuses.'.$state))
                ->color(fn (string $state) => match ($state) {
                    'pending' => 'warning', 'done' => 'success', default => 'gray',
                }),
            TextColumn::make('note')->label(__('admin.finance.reason'))->wrap()->placeholder('–'),
        ])->filters([
            SelectFilter::make('status')->label(__('admin.event.status'))->options(__('public.statuses')),
        ])->recordActions([
            ActionGroup::make([
                Action::make('show')->label(__('public.answers'))->icon('heroicon-o-eye')->modalSubmitAction(false)->visible(fn (PublicSubmission $record) => $record->data !== null)
                    ->schema(fn (PublicSubmission $record) => self::entries($record)),
                Action::make('approve')->label(__('public.approve'))->icon('heroicon-o-check')->color('success')->requiresConfirmation()->visible(fn (PublicSubmission $record) => $record->status === 'pending')
                    ->action(fn (PublicSubmission $record) => app(PublicForms::class)->approve($record)),
                Action::make('decline')->label(__('public.decline'))->icon('heroicon-o-x-mark')->color('danger')->visible(fn (PublicSubmission $record) => $record->status === 'pending')
                    ->schema([Textarea::make('note')->label(__('admin.finance.reason'))->maxLength(250)])
                    ->action(fn (PublicSubmission $record, array $data) => app(PublicForms::class)->decline($record, $data['note'] ?? null)),
            ]),
        ], RecordActionsPosition::BeforeColumns);
    }

    /** @return list<TextEntry> */
    private static function entries(PublicSubmission $record): array
    {
        $data = $record->data ?? [];
        $entries = [];
        foreach (['first_name' => __('admin.person.first_name'), 'last_name' => __('admin.person.last_name'), 'email' => __('lists.email'), 'birth_date' => __('admin.person.birth_date'), 'phone' => __('lists.phone')] as $key => $label) {
            $entries[] = TextEntry::make($key)->label($label)->state($data[$key] ?? null)->placeholder('–');
        }
        $user = auth()->user();
        $safety = $user instanceof User && EventLists::mayViewSafety($user);
        $form = $record->publicForm !== null ? app(PublicForms::class)->questions($record->publicForm) : null;
        foreach ($form->fields ?? [] as $field) {
            /** @var FormField $field */
            if (! $field->isConfidential() || $safety) {
                $entries[] = TextEntry::make('v_'.$field->key)->label($field->labelText())->state(FormValue::text($field, $data['values'][$field->key] ?? null))->placeholder('–');
            }
        }

        return $entries;
    }

    public static function getPages(): array
    {
        return ['index' => ListPublicSubmissions::route('/')];
    }
}

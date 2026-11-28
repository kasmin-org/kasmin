<?php

namespace App\Filament\Resources\JournalEntries;

use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\JournalEntries\Pages\ManageJournalEntries;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Filament\Support\Money as MoneyInput;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Period;
use App\Models\Person;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\Journal;
use App\Support\Accounting\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The journal (E1): every booking with its lines. Typed-in bookings have any number of lines (split bookings);
 * bookings are never changed, only reversed.
 */
class JournalEntryResource extends Resource
{
    use AreaAccess;
    use InArea;

    protected static ?string $model = JournalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    public static function area(): string
    {
        return 'finance';
    }

    public static function getModelLabel(): string
    {
        return __('accounting.entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.journal');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * The form of a typed-in booking.
     *
     * @return list<Component|Field>
     */
    public static function entrySchema(): array
    {
        $accounts = fn () => Account::query()->where('active', true)->orderBy('sort')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all();
        $isResult = fn ($get) => in_array(Account::query()->whereKey((int) $get('account'))->value('type'), ['income', 'expense'], true);
        $isFund = fn ($get) => Account::query()->whereKey((int) $get('account'))->value('system_key') === 'funds';
        $isPersonal = fn ($get) => in_array(Account::query()->whereKey((int) $get('account'))->value('system_key'), Journal::PERSONAL, true);

        return [
            DatePicker::make('date')->label(__('accounting.date'))->required()->default(now()),
            TextInput::make('text')->label(__('accounting.text'))->required()->maxLength(250),
            TextInput::make('receipt')->label(__('accounting.receipt'))->maxLength(120),
            FileUpload::make('receipt_path')->label(__('accounting.receipt_file'))->disk('local')->directory('receipts')->visibility('private')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/heic'])->maxSize(10240),
            Repeater::make('lines')->label(__('accounting.lines'))->minItems(2)->defaultItems(2)->columns(['md' => 5])->addActionLabel(__('accounting.add_line'))->schema([
                Select::make('account')->label(__('accounting.account'))->required()->searchable()->options($accounts)->live()->columnSpan(['md' => 2]),
                MoneyInput::input('debit', __('accounting.debit')),
                MoneyInput::input('credit', __('accounting.credit')),
                Select::make('cost_center')->label(__('accounting.cost_center'))->visible(fn ($get) => Accounting::costCenters() && $isResult($get))
                    ->options(fn ($get) => CostCenter::query()->whereHas('periods', fn ($q) => $q->whereKey(Period::of($get('../../date') ?? now())->id ?? 0))->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder(__('accounting.cost_centers.default_placeholder')),
                Select::make('fund')->label(__('accounting.fund'))->visible(fn ($get) => Accounting::funds() && $isFund($get))
                    ->options(fn () => Fund::query()->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('person_id')->label(__('accounting.reports.person'))->visible($isPersonal)->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Person::query()->whereNull('anonymised_at')->where(fn ($query) => $query->where('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->fullName()])->all())
                    ->getOptionLabelUsing(fn ($value) => Person::query()->whereKey((int) $value)->first()?->fullName()),
                TextInput::make('text')->label(__('accounting.line_text'))->maxLength(250)->columnSpan(['md' => 2]),
            ]),
            Text::make(__('accounting.entry_hint')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('number', 'desc')->modifyQueryUsing(fn (Builder $query) => $query->with('lines.account'))->columns([
            TextColumn::make('number')->label(__('accounting.number_short'))->sortable(),
            TextColumn::make('date')->label(__('accounting.date'))->date()->sortable(),
            TextColumn::make('text')->label(__('accounting.text'))->searchable()->wrap()
                ->description(fn (JournalEntry $record) => $record->lines->map(fn (JournalLine $l) => ($l->debit_cents > 0 ? '' : '  ← ').$l->account?->name)->implode(' · ')),
            TextColumn::make('kind')->label(__('accounting.kind'))->badge()->formatStateUsing(fn (string $state) => __('accounting.kinds.'.$state)),
            TextColumn::make('total')->label(__('accounting.amount'))->alignEnd()->state(fn (JournalEntry $record) => Money::format($record->totalCents())),
        ])->filters([
            SelectFilter::make('kind')->label(__('accounting.kind'))->options(__('accounting.kinds')),
            SelectFilter::make('in_period')->label(__('accounting.period'))->options(fn () => Period::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data) => ($p = Period::query()->whereKey((int) ($data['value'] ?? 0))->first()) !== null ? $query->whereBetween('date', [$p->starts_on->toDateString(), $p->ends_on->toDateString()]) : $query),
            SelectFilter::make('on_account')->label(__('accounting.account'))->options(fn () => Account::query()->orderBy('sort')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereHas('lines', fn ($l) => $l->where('account_id', $data['value'])) : $query),
        ])->recordActions([
            Action::make('lines')->label(__('accounting.show'))->icon('heroicon-o-eye')->color('gray')->modalSubmitAction(false)
                ->modalContent(fn (JournalEntry $record) => new HtmlString(view('filament.accounting.entry', ['entry' => $record->load('lines.account', 'lines.costCenter', 'lines.fund', 'lines.person')])->render())),
            Action::make('reverse')->label(__('accounting.reverse'))->icon('heroicon-o-arrow-uturn-left')->color('danger')->requiresConfirmation()
                ->modalDescription(__('accounting.reverse_hint'))
                ->visible(fn (JournalEntry $record) => self::canCreate() && Journal::reversible($record))
                ->action(fn (JournalEntry $record) => CostCenterResource::attempt(fn () => app(Journal::class)->reverse($record))),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageJournalEntries::route('/')];
    }
}

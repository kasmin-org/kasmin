<?php

namespace App\Filament\Resources\PublicForms;

use App\Filament\Resources\PublicForms\Pages\ListPublicForms;
use App\Filament\Support\AreaAccess;
use App\Models\Event;
use App\Models\Form;
use App\Models\Group;
use App\Models\MembershipType;
use App\Models\PublicForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Public forms (PF01, PF03, PF04).
 */
class PublicFormResource extends Resource
{
    use AreaAccess;

    public static function area(): string
    {
        return 'forms';
    }

    protected static ?string $model = PublicForm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('public.form');
    }

    public static function getPluralModelLabel(): string
    {
        return __('public.forms');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.forms');
    }

    public static function form(Schema $schema): Schema
    {
        $is = fn (string ...$kinds) => fn ($get) => in_array($get('kind'), $kinds, true);

        return $schema->components([
            Select::make('kind')->label(__('public.kind'))->options(__('public.kinds'))->required()->live(),
            TextInput::make('slug')->label(__('public.slug'))->required()->alphaDash()->maxLength(64)->unique(ignoreRecord: true)->helperText(__('public.slug_hint')),
            TextInput::make('title.de')->label(__('public.title').' (DE)')->required()->maxLength(150),
            TextInput::make('title.en')->label(__('public.title').' (EN)')->required()->maxLength(150),
            Textarea::make('intro.de')->label(__('public.intro').' (DE)')->rows(3),
            Textarea::make('intro.en')->label(__('public.intro').' (EN)')->rows(3),
            Select::make('event_id')->label(__('admin.event.singular'))->visible($is('event'))->required($is('event'))
                ->options(fn () => Event::query()->where('ends_at', '>=', now())->orderBy('starts_at')->pluck('title', 'id')->all()),
            Select::make('role_key')->label(__('admin.event.role'))->options(__('events.roles'))->visible($is('event'))->required($is('event'))->default('guest'),
            Select::make('form_id')->label(__('public.questions'))->visible($is('application'))
                ->options(fn () => Form::query()->where('kind', 'registration')->get()->mapWithKeys(fn (Form $f) => [$f->id => $f->label()])->all()),
            Select::make('group_id')->label(__('public.group'))->options(fn () => Group::query()->where('kind', 'manual')->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('membership_type_id')->label(__('admin.finance.membership_type'))->visible($is('application'))
                ->options(fn () => MembershipType::query()->get()->mapWithKeys(fn (MembershipType $t) => [$t->id => $t->label()])->all()),
            Toggle::make('needs_approval')->label(__('public.needs_approval'))->default(true)->visible($is('application')),
            DateTimePicker::make('opens_at')->label(__('public.opens_at'))->seconds(false),
            DateTimePicker::make('closes_at')->label(__('public.closes_at'))->seconds(false)->after('opens_at'),
            Toggle::make('active')->label(__('admin.form.active')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label(__('public.title'))->state(fn (PublicForm $record) => $record->titleText())->description(fn (PublicForm $record) => '/f/'.$record->slug),
            TextColumn::make('kind')->label(__('public.kind'))->formatStateUsing(fn (string $state) => __('public.kinds.'.$state))->badge(),
            IconColumn::make('active')->label(__('admin.form.active'))->boolean(),
        ])->recordActions([
            Action::make('open')->label(__('public.open_page'))->icon('heroicon-o-arrow-top-right-on-square')->url(fn (PublicForm $record) => route('public.form', $record->slug), true)->visible(fn (PublicForm $record) => $record->active),
            EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPublicForms::route('/')];
    }
}

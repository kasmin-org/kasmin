<?php

namespace App\Filament\Resources\PublicForms;

use App\Filament\Resources\PublicForms\Pages\ListPublicForms;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
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
use Illuminate\Support\HtmlString;

/**
 * Public forms (PF01, PF03, PF04).
 */
class PublicFormResource extends Resource
{
    use AreaAccess;
    use InArea;

    public static function area(): string
    {
        return 'forms';
    }

    protected static ?string $model = PublicForm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    public static function getModelLabel(): string
    {
        return __('public.form');
    }

    public static function getPluralModelLabel(): string
    {
        return __('public.forms');
    }

    public static function form(Schema $schema): Schema
    {
        $is = fn (string ...$kinds) => fn ($get) => in_array($get('kind'), $kinds, true);

        return $schema->components([
            Select::make('kind')->label(__('public.kind'))->options(__('public.kinds'))->required()->live(),
            TextInput::make('title')->label(__('public.title'))->required()->maxLength(150)->live(onBlur: true)
                // Suggest the address from the title as long as it was not changed by hand.
                ->afterStateUpdated(function ($get, $set, ?string $old, ?string $state) {
                    $slug = (string) $get('slug');
                    if ($slug === '' || PublicForm::slugFrom($slug) === PublicForm::slugFrom($old)) {
                        $set('slug', PublicForm::slugFrom($state));
                    }
                }),
            TextInput::make('slug')->label(__('public.slug'))->required()->maxLength(80)->live(debounce: 400)
                ->dehydrateStateUsing(fn (?string $state) => PublicForm::slugFrom($state))
                ->rule(fn (?PublicForm $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record) {
                    $slug = PublicForm::slugFrom(is_string($value) ? $value : '');
                    if ($slug === '') {
                        $fail(__('public.slug_empty'));
                    } elseif (PublicForm::slugTaken($slug, $record?->id)) {
                        $fail(__('public.slug_taken', ['slug' => $slug]));
                    }
                })
                ->helperText(fn ($get, ?PublicForm $record) => self::addressPreview((string) $get('slug'), $record)),
            Textarea::make('intro')->label(__('public.intro'))->rows(3),
            Select::make('event_id')->label(__('admin.event.singular'))->visible($is('event'))->required($is('event'))
                ->options(fn () => Event::query()->where('ends_at', '>=', now())->orderBy('starts_at')->pluck('title', 'id')->all()),
            Select::make('role_key')->label(__('admin.event.role'))->options(__('events.roles'))->visible($is('event'))->required($is('event'))->default('guest'),
            Select::make('form_id')->label(__('public.questions'))->visible($is('application'))
                ->options(fn () => Form::query()->where('kind', 'registration')->get()->mapWithKeys(fn (Form $f) => [$f->id => $f->label()])->all()),
            Select::make('group_id')->label(__('public.group'))->helperText(fn ($get) => $get('kind') === 'signup' ? __('public.signup_group_hint') : null)->options(fn () => Group::query()->where('kind', 'manual')->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('membership_type_id')->label(__('admin.finance.membership_type'))->visible($is('application'))
                ->options(fn () => MembershipType::query()->get()->mapWithKeys(fn (MembershipType $t) => [$t->id => $t->label()])->all()),
            Toggle::make('needs_approval')->label(__('public.needs_approval'))->default(true)->visible($is('application')),
            DateTimePicker::make('opens_at')->label(__('public.opens_at'))->seconds(false),
            DateTimePicker::make('closes_at')->label(__('public.closes_at'))->seconds(false)->after('opens_at'),
            Toggle::make('ask_newsletter')->label(__('public.ask_newsletter'))->visible($is('event', 'application', 'signup')),
            Toggle::make('active')->label(__('admin.form.active'))->helperText(fn ($get) => $get('kind') === 'signup' ? __('public.signup_active_hint') : null),
            Toggle::make('on_start_page')->label(__('public.on_start_page'))->visible(fn ($get) => $get('kind') !== 'signup'),
        ]);
    }

    /** The full address the form gets, while typing, with a button to copy it (B15). */
    public static function addressPreview(string $typed, ?PublicForm $record): HtmlString
    {
        $slug = PublicForm::slugFrom($typed);
        if ($slug === '') {
            return new HtmlString(e(__('public.slug_hint')));
        }
        $url = route('public.form', $slug);
        $moved = $record !== null && $record->exists && $record->slug !== $slug ? '<br>'.e(__('public.slug_moved', ['old' => route('public.form', $record->slug)])) : '';
        $except = $record !== null && $record->exists ? $record->id : null;
        $forwarding = PublicForm::query()->when($except !== null, fn ($query) => $query->whereKeyNot($except))->whereJsonContains('old_slugs', $slug)->first();
        if ($forwarding !== null) {
            $moved .= '<br>'.e(__('public.slug_takes_forward', ['title' => $forwarding->titleText()]));
        }

        return new HtmlString(e(__('public.slug_preview')).' <code class="break-all font-semibold">'.e($url).'</code>'
            .' <button type="button" class="ms-1 underline" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText('.e((string) json_encode($url)).'); copied = true; setTimeout(() => copied = false, 2000)"'
            .' x-text="copied ? '.e((string) json_encode(__('public.copied'))).' : '.e((string) json_encode(__('public.copy'))).'">'.e(__('public.copy')).'</button>'.$moved);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label(__('public.title'))->state(fn (PublicForm $record) => $record->titleText())->description(fn (PublicForm $record) => $record->url()),
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

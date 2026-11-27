<?php

namespace App\Filament\Resources\MailTemplates\Tables;

use App\Filament\Resources\MailTemplates\MailTemplateResource;
use App\Models\MailTemplate;
use App\Support\Auth\Permissions;
use App\Support\Mail\TemplateStandards;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class MailTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('admin.form.name'))->state(fn (MailTemplate $r) => $r->label())->searchable(['key']),
            TextColumn::make('group')->label(__('admin.mail.group'))->formatStateUsing(fn ($state) => __('admin.mail.groups.'.($state ?: 'general')))->badge()->color('gray'),
            TextColumn::make('category')->label(__('admin.mail.category'))->formatStateUsing(fn ($state) => __('admin.mail.categories.'.$state))->badge(),
            TextColumn::make('version')->label(__('admin.form.version')),
            TextColumn::make('hint')->label('')->state(fn (MailTemplate $r) => app(TemplateStandards::class)->isNew($r) ? __('admin.mail.new_standard') : null)->badge()->color('warning'),
            IconColumn::make('is_standard')->label(__('admin.mail.standard'))->boolean(),
        ])->groups([
            Group::make('group')->label(__('admin.mail.group'))->getTitleFromRecordUsing(fn (MailTemplate $r) => (string) __('admin.mail.groups.'.$r->groupKey()))
                ->getDescriptionFromRecordUsing(fn (MailTemplate $r) => (string) __('admin.mail.group_fields.'.$r->groupKey()))->collapsible(),
        ])->defaultGroup('group')->filters([
            SelectFilter::make('group')->label(__('admin.mail.group'))->options(array_combine(MailTemplate::GROUPS, array_map(fn (string $g) => __('admin.mail.groups.'.$g), MailTemplate::GROUPS))),
            SelectFilter::make('category')->label(__('admin.mail.category'))->options(array_combine(MailTemplate::CATEGORIES, array_map(fn ($c) => __('admin.mail.categories.'.$c), MailTemplate::CATEGORIES))),
        ])->recordActions([ActionGroup::make([
            Action::make('preview')->label(__('admin.mail.preview'))->icon('heroicon-o-eye')->color('gray')->url(fn (MailTemplate $record) => MailTemplateResource::getUrl('preview', ['record' => $record])),
            EditAction::make(),
            Action::make('standard')->label(__('admin.mail.compare'))->icon('heroicon-o-arrows-right-left')
                ->color(fn (MailTemplate $record) => app(TemplateStandards::class)->isNew($record) ? 'warning' : 'gray')
                ->visible(fn (MailTemplate $record) => app(TemplateStandards::class)->differs($record))
                ->modalContent(fn (MailTemplate $record) => view('filament.mail-diff', ['diff' => app(TemplateStandards::class)->diff($record)]))
                ->modalSubmitActionLabel(__('admin.mail.adopt'))->modalSubmitAction(fn () => Permissions::current('mailings', 'edit') ? null : false)
                ->extraModalFooterActions([
                    Action::make('keep')->label(__('admin.mail.keep'))->color('gray')->visible(fn () => Permissions::current('mailings', 'edit'))->cancelParentActions()
                        ->action(fn (MailTemplate $record) => app(TemplateStandards::class)->keep($record)),
                ])
                ->action(function (MailTemplate $record) {
                    abort_unless(Permissions::current('mailings', 'edit'), 403);
                    app(TemplateStandards::class)->adopt($record);
                    Notification::make()->title(__('admin.mail.adopted'))->success()->send();
                }),
        ])], RecordActionsPosition::BeforeColumns);
    }
}

<?php

namespace App\Filament\Resources\Mailings\Schemas;

use App\Models\MailTemplate;
use App\Models\Event;
use App\Models\EventDocument;
use App\Models\Group;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MailingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.mail.mailing'))->columns(2)->schema([
                TextInput::make('name')->label(__('admin.mail.name'))->required(),
                Select::make('mail_template_id')->label(__('admin.mail.template'))->required()
                    // A mailing is about nothing in particular or about an event: templates of other groups (invoice,
                    // registration, membership) and mails of the system have placeholders a mailing cannot fill.
                    ->options(fn ($get) => MailTemplate::forMailings(filled($get('recipients.event_id')))->orderBy('key')->get()->mapWithKeys(fn (MailTemplate $t) => [$t->id => $t->label().' · '.__('admin.mail.groups.'.$t->groupKey())])->all())
                    ->helperText(__('admin.mail.mailing_templates_hint')),
                DateTimePicker::make('scheduled_at')->label(__('admin.mail.scheduled_at'))->seconds(false),
                FileUpload::make('attachments')->label(__('admin.mail.attachments'))->multiple()->disk('local')->directory('mail-attachments')->visibility('private')->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file) => Str::lower(Str::random(6)).'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->guessExtension())->maxSize(5120)->maxFiles(5)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->helperText(__('admin.mail.attachments_hint'))->columnSpanFull(),
                Select::make('document_ids')->label(__('admin.event.documents'))->multiple()->options(fn () => EventDocument::query()->with('event')->get()->filter(fn (EventDocument $d) => $d->event !== null)->mapWithKeys(fn (EventDocument $d) => [$d->id => $d->event?->title.': '.$d->title])->all())->columnSpanFull(),
            ]),
            Section::make(__('admin.mail.recipients'))->columns(2)->schema([
                Select::make('recipients.group_ids')->label(__('admin.group.plural'))->multiple()->options(fn () => Group::query()->pluck('name', 'id')),
                Select::make('recipients.event_id')->label(__('admin.event.singular'))->options(fn () => Event::query()->orderByDesc('starts_at')->pluck('title', 'id')),
                Select::make('recipients.statuses')->label(__('admin.event.status'))->multiple()->options(__('events.status')),
                Select::make('routing')->label(__('admin.mail.routing'))->options(__('admin.mail.audiences'))->default('guardians')->required(),
                Toggle::make('one_per_family')->label(__('admin.mail.one_per_family'))->default(true),
                Toggle::make('edit_before_sending')->label(__('admin.mail.edit_before_sending')),
            ]),
        ]);
    }
}

<?php

namespace App\Filament\Resources\Mailings\Schemas;

use App\Models\Event;
use App\Models\Group;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MailingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.mail.mailing'))->columns(2)->schema([
                TextInput::make('name')->label(__('admin.mail.name'))->required(),
                Select::make('mail_template_id')->label(__('admin.mail.template'))->relationship('template', 'key')->required(),
                Select::make('sender_profile_id')->label(__('admin.mail.sender_profile'))->relationship('template.senderProfile', 'name')->placeholder(__('admin.mail.from_template')),
                DateTimePicker::make('scheduled_at')->label(__('admin.mail.scheduled_at'))->seconds(false),
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

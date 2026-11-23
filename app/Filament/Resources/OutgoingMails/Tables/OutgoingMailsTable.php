<?php

namespace App\Filament\Resources\OutgoingMails\Tables;

use App\Models\OutgoingMail;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Mail log (ML01–ML03): every mail with status; resend with one click.
 */
class OutgoingMailsTable
{
    public static function configure(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label(__('admin.mail.created_at'))->dateTime()->sortable(),
            TextColumn::make('to_name')->label(__('admin.mail.to'))->description(fn (OutgoingMail $r) => $r->to_address)->searchable(['to_name', 'to_address']),
            TextColumn::make('subject')->label(__('admin.mail.subject'))->limit(60)->searchable(),
            TextColumn::make('kind')->label(__('admin.mail.kind'))->formatStateUsing(fn ($state) => __('admin.mail.kinds.'.$state))->badge(),
            TextColumn::make('status')->label(__('admin.event.status'))->formatStateUsing(fn ($state) => __('admin.mail.statuses.'.$state))->badge()
                ->color(fn ($state) => match ($state) { 'sent' => 'success', 'failed', 'bounced' => 'danger', 'queued', 'held', 'sending' => 'warning', default => 'gray' }),
        ])->filters([
            SelectFilter::make('status')->label(__('admin.event.status'))->options(__('admin.mail.statuses')),
            SelectFilter::make('kind')->label(__('admin.mail.kind'))->options(__('admin.mail.kinds')),
        ])->recordActions([
            ViewAction::make(),
            Action::make('resend')->label(__('admin.mail.resend'))->icon('heroicon-o-arrow-path')->requiresConfirmation()
                ->visible(fn (OutgoingMail $r) => in_array($r->status, ['sent', 'failed', 'bounced'], true))
                ->action(fn (OutgoingMail $r) => $r->replicate(['uuid', 'message_id', 'sent_at', 'imap_copied_at', 'attempts', 'last_error'])->fill(['status' => 'queued', 'created_by_id' => auth()->id()])->save()),
        ]);
    }
}

<?php

namespace App\Filament\Resources\Mailings\Tables;

use App\Models\Mailing;
use App\Support\Auth\Permissions;
use App\Support\Mail\Mailings;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MailingsTable
{
    private static function mayEdit(): bool
    {
        return Permissions::current('mailings', 'edit');
    }

    public static function configure(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('name')->label(__('admin.mail.name'))->searchable(),
            TextColumn::make('status')->label(__('admin.event.status'))->formatStateUsing(fn ($state) => __('admin.mail.mailing_status.'.$state))->badge(),
            TextColumn::make('mails_count')->counts('mails')->label(__('admin.mail.mails')),
            TextColumn::make('sent_at')->label(__('admin.mail.sent_at'))->dateTime(),
        ])->recordActions([
            Action::make('prepare')->label(__('admin.mail.prepare'))->icon('heroicon-o-document-magnifying-glass')
                ->visible(fn (Mailing $r) => self::mayEdit() && in_array($r->status, ['draft', 'prepared'], true))
                ->requiresConfirmation()->modalDescription(fn (Mailing $r) => __('admin.mail.prepare_hint', [
                    'count' => count(app(Mailings::class)->plan($r)['mails']), 'excluded' => count(app(Mailings::class)->plan($r)['excluded']),
                ]))
                ->action(fn (Mailing $r) => Notification::make()->title(__('admin.mail.prepared', ['count' => app(Mailings::class)->prepare($r)]))->success()->send()),
            Action::make('send')->label(__('admin.mail.send'))->icon('heroicon-o-paper-airplane')->color('success')
                ->visible(fn (Mailing $r) => self::mayEdit() && $r->status === 'prepared')->requiresConfirmation()
                ->action(fn (Mailing $r) => Notification::make()->title(__('admin.mail.released', ['count' => app(Mailings::class)->release($r)]))->success()->send()),
            Action::make('cancel')->label(__('admin.mail.cancel'))->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (Mailing $r) => self::mayEdit() && in_array($r->status, ['prepared', 'sending'], true))->requiresConfirmation()
                ->action(fn (Mailing $r) => app(Mailings::class)->cancel($r)),
            EditAction::make()->visible(fn (Mailing $r) => in_array($r->status, ['draft', 'prepared'], true)),
        ]);
    }
}

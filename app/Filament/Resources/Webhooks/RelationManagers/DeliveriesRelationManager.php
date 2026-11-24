<?php

namespace App\Filament\Resources\Webhooks\RelationManagers;

use App\Models\WebhookDelivery;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('webhooks.deliveries');
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label(__('webhooks.time'))->dateTime(),
            TextColumn::make('event')->label(__('webhooks.event'))->badge(),
            TextColumn::make('status')->label(__('webhooks.status'))->badge()
                ->color(fn (string $state) => match ($state) {
                    'sent' => 'success', 'failed' => 'danger', default => 'warning'
                }),
            TextColumn::make('attempts')->label(__('webhooks.attempts')),
            TextColumn::make('response_code')->label('HTTP')->placeholder('–'),
            TextColumn::make('error')->label(__('webhooks.error'))->limit(60)->placeholder('–')->wrap(),
        ])->recordActions([
            Action::make('retry')->label(__('webhooks.retry'))->icon('heroicon-o-arrow-path')
                ->visible(fn (WebhookDelivery $r) => $r->status === 'failed')
                ->action(fn (WebhookDelivery $r) => $r->forceFill(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now()])->save()),
        ]);
    }
}

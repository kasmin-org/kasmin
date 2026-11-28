<?php

namespace App\Filament\Resources\AutomationRuns;

use App\Filament\Resources\AutomationRuns\Pages\ListAutomationRuns;
use App\Filament\Resources\AutomationRuns\Pages\ViewAutomationRun;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Models\AutomationApproval;
use App\Models\AutomationRun;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Approvals inbox and run history (AD52, AT05–AT08).
 */
class AutomationRunResource extends Resource
{
    use AreaAccess;
    use InArea;

    public static function area(): string
    {
        return 'automations';
    }

    protected static ?string $model = AutomationRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    public static function getModelLabel(): string
    {
        return __('automations.run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('automations.runs');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = AutomationRun::query()->where('status', 'pending_approval')->where('expires_at', '>', now())->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'pending_approval' => 'warning', 'done' => 'success', 'failed', 'denied' => 'danger', default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('automation.name')->label(__('automations.singular'))->state(fn (AutomationRun $record) => $record->automation?->label())->wrap(),
                TextColumn::make('status')->label(__('admin.event.status'))->badge()->formatStateUsing(fn (string $state) => __('automations.statuses.'.$state))
                    ->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('event.title')->label(__('automations.event'))->placeholder('–')->toggleable(),
                TextColumn::make('items')->label(__('automations.planned'))->state(fn (AutomationRun $record) => count($record->plan)),
                TextColumn::make('created_at')->label(__('automations.created'))->since()->sortable(),
                TextColumn::make('expires_at')->label(__('automations.expires'))->dateTime()->toggleable()->placeholder('–'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.event.status'))->options(__('automations.statuses')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('automation')->label(__('automations.singular'))->state(fn (AutomationRun $record) => $record->automation?->label()),
                TextEntry::make('status')->label(__('admin.event.status'))->badge()->formatStateUsing(fn (string $state) => __('automations.statuses.'.$state))
                    ->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('trigger')->label(__('automations.trigger_label'))->formatStateUsing(fn (string $state) => __('automations.triggers.'.$state)),
                TextEntry::make('event.title')->label(__('automations.event'))->placeholder('–'),
                TextEntry::make('created_at')->label(__('automations.created'))->dateTime(),
                TextEntry::make('expires_at')->label(__('automations.expires'))->dateTime()->placeholder('–'),
                TextEntry::make('approvals_so_far')->label(__('automations.approvals_so_far'))->visible(fn (AutomationRun $record) => ($record->automation->approvals_needed ?? 1) > 1)
                    ->state(fn (AutomationRun $record) => $record->approvals()->count().' / '.$record->automation?->approvals_needed.($record->approvals()->exists() ? ': '.$record->approvals()->with('user')->get()->map(fn (AutomationApproval $a) => $a->user?->name)->implode(', ') : '')),
                TextEntry::make('decidedBy.name')->label(__('automations.decided_by'))->placeholder('–'),
                TextEntry::make('comment')->label(__('automations.comment'))->placeholder('–'),
            ]),
            Section::make(__('automations.planned'))->columnSpanFull()->schema([
                RepeatableEntry::make('rows')->hiddenLabel()->columns(3)
                    ->state(fn (AutomationRun $record) => array_map(fn (array $item) => [
                        'label' => $item['label'].(! empty($item['details']) ? ' – '.$item['details'] : ''),
                        'result' => in_array($item['key'], $record->excluded ?? [], true) ? __('automations.excluded') : (string) ($record->result[$item['key']] ?? '–'),
                    ], $record->plan))
                    ->schema([
                        TextEntry::make('label')->hiddenLabel()->columnSpan(2),
                        TextEntry::make('result')->hiddenLabel(),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationRuns::route('/'),
            'view' => ViewAutomationRun::route('/{record}'),
        ];
    }
}

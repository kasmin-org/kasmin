<?php

namespace App\Filament\Resources\AuditEvents;

use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Support\AreaAccess;
use App\Models\AuditEvent;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit log viewer (AD70, AU05): read only, filters by action, actor, object and date.
 */
class AuditEventResource extends Resource
{
    use AreaAccess;

    public static function area(): string
    {
        return 'audit';
    }

    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getModelLabel(): string
    {
        return __('privacy.audit_entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('privacy.audit');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.privacy');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('occurred_at')->label(__('privacy.time'))->dateTime()->sortable(),
            TextColumn::make('action')->label(__('privacy.action'))->badge()->searchable(),
            TextColumn::make('actor_type')->label(__('privacy.actor'))->state(fn (AuditEvent $r) => $r->actor_type.($r->actor_id ? ' #'.$r->actor_id : '')),
            TextColumn::make('subject_type')->label(__('privacy.object'))->state(fn (AuditEvent $r) => $r->subject_type ? class_basename($r->subject_type).' #'.$r->subject_id : '–')->toggleable(),
            TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Filter::make('action')->schema([TextInput::make('prefix')->label(__('privacy.action_prefix'))->placeholder('login.')])
                ->query(fn (Builder $q, array $data) => $q->when($data['prefix'] ?? null, fn ($q, $p) => $q->where('action', 'like', $p.'%'))),
            SelectFilter::make('actor_type')->label(__('privacy.actor'))->options(['user' => 'user', 'system' => 'system', 'api' => 'api', 'mcp' => 'mcp', 'automation' => 'automation']),
            Filter::make('date')->schema([DatePicker::make('from')->label(__('privacy.from')), DatePicker::make('to')->label(__('privacy.to'))])
                ->query(fn (Builder $q, array $data) => $q->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '>=', $d))->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '<=', $d))),
        ])->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('occurred_at')->label(__('privacy.time'))->dateTime(),
            TextEntry::make('action')->label(__('privacy.action')),
            TextEntry::make('actor_type')->label(__('privacy.actor'))->state(fn (AuditEvent $r) => $r->actor_type.($r->actor_id ? ' #'.$r->actor_id : '')),
            TextEntry::make('subject_type')->label(__('privacy.object'))->state(fn (AuditEvent $r) => $r->subject_type ? class_basename($r->subject_type).' #'.$r->subject_id : '–'),
            TextEntry::make('ip')->label('IP')->placeholder('–'),
            TextEntry::make('request_id')->label('Request')->placeholder('–'),
            TextEntry::make('changes')->label(__('privacy.changes'))->columnSpanFull()->state(fn (AuditEvent $r) => $r->changes ? json_encode($r->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '–')->extraAttributes(['style' => 'white-space:pre-wrap;font-family:monospace']),
            TextEntry::make('meta')->label(__('privacy.details'))->columnSpanFull()->state(fn (AuditEvent $r) => $r->meta ? json_encode($r->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '–')->extraAttributes(['style' => 'white-space:pre-wrap;font-family:monospace']),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditEvents::route('/')];
    }
}

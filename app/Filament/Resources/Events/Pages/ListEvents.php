<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Support\ListTools;
use App\Models\EventTemplate;
use App\Support\Events\EventCopier;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListEvents extends ListRecords
{
    use ListTools;

    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(),
            Action::make('from_template')->label(__('admin.event.from_template'))->icon('heroicon-o-bookmark-square')->color('gray')
                ->visible(fn () => EventResource::canCreate())
                ->schema([
                    Select::make('template')->label(__('admin.event.template'))->options(fn () => EventTemplate::query()->orderBy('title')->pluck('title', 'id')->all())->required(),
                    ...EventsTable::copyFields(),
                ])
                ->action(fn (array $data) => $this->redirect(EventResource::getUrl('edit', ['record' => app(EventCopier::class)->copy(EventTemplate::query()->findOrFail((int) $data['template']), (string) $data['title'], Carbon::parse((string) $data['starts_on']))]))),
            CreateAction::make(),
        ];
    }
}

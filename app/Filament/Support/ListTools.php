<?php

namespace App\Filament\Support;

use App\Models\SavedView;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Lists\ListExport;
use App\Support\Lists\ListTable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export of what the table shows (AD13) and saved views (AD11) for list pages.
 */
trait ListTools
{
    private const EXPORT_LIMIT = 5000;

    private const PDF_LIMIT = 1000;

    /** @return list<ActionGroup> */
    protected function listTools(): array
    {
        $views = [];
        foreach ($this->savedViews() as $view) {
            $views[] = Action::make('view_'.$view->id)->label($view->name)->icon($view->shared ? 'heroicon-o-users' : 'heroicon-o-bookmark')
                ->action(fn () => $this->applySavedView($view->id));
        }

        return [
            ActionGroup::make([
                ...$views,
                Action::make('save_view')->label(__('lists.save_view'))->icon('heroicon-o-plus')
                    ->schema([
                        TextInput::make('name')->label(__('lists.name'))->required()->maxLength(80),
                        Toggle::make('shared')->label(__('lists.share_view')),
                    ])
                    ->action(fn (array $data) => $this->saveView((string) $data['name'], (bool) ($data['shared'] ?? false))),
                Action::make('delete_view')->label(__('lists.delete_view'))->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn () => $this->deletableViews() !== [])
                    ->schema([Select::make('view')->label(__('lists.view'))->options(fn () => $this->deletableViews())->required()])
                    ->action(fn (array $data) => SavedView::query()->whereKey(array_key_exists((int) $data['view'], $this->deletableViews()) ? (int) $data['view'] : 0)->delete()),
            ])->label(__('lists.views'))->icon('heroicon-o-bookmark')->color('gray')->button(),
            ActionGroup::make(array_map(
                fn (string $format) => Action::make('export_'.$format)->label(strtoupper($format))->action(fn () => $this->exportTable($format)),
                ['xlsx', 'csv', 'pdf'],
            ))->label(__('lists.export'))->icon('heroicon-o-arrow-down-tray')->color('gray')->button(),
        ];
    }

    private function viewPage(): string
    {
        return class_basename(static::class);
    }

    private function viewUser(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }

    /** @return iterable<SavedView> */
    private function savedViews(): iterable
    {
        return SavedView::visibleTo($this->viewUser(), $this->viewPage())->limit(30)->get();
    }

    /** @return array<int, string> own views; administrators may also delete shared ones */
    private function deletableViews(): array
    {
        $user = $this->viewUser();
        $admin = $user->hasAnyRole(['administrator']);

        return SavedView::visibleTo($user, $this->viewPage())->get()->filter(fn (SavedView $v) => $v->user_id === $user->id || $admin)->pluck('name', 'id')->all();
    }

    private function saveView(string $name, bool $shared): void
    {
        $columns = [];
        foreach ($this->tableColumns as $column) {
            $columns[$column['name']] = $column['isToggled'];
        }
        SavedView::query()->updateOrCreate(
            ['user_id' => $this->viewUser()->id, 'page' => $this->viewPage(), 'name' => $name],
            ['shared' => $shared, 'state' => ['filters' => $this->tableFilters, 'sort' => $this->tableSort, 'search' => $this->tableSearch, 'columns' => $columns]],
        );
        Notification::make()->title(__('lists.view_saved'))->success()->send();
    }

    private function applySavedView(int $id): void
    {
        $view = SavedView::visibleTo($this->viewUser(), $this->viewPage())->find($id);
        if ($view === null) {
            return;
        }
        $state = $view->state;
        $this->tableFilters = is_array($state['filters'] ?? null) ? $state['filters'] : null;
        $this->tableDeferredFilters = $this->tableFilters;
        $this->getTableFiltersForm()->fill($this->tableFilters);
        $this->tableSort = is_string($state['sort'] ?? null) ? $state['sort'] : null;
        $this->tableSearch = is_string($state['search'] ?? null) ? $state['search'] : '';
        foreach ($this->tableColumns as $i => $column) {
            if ($column['isToggleable'] && array_key_exists($column['name'], (array) ($state['columns'] ?? []))) {
                $this->tableColumns[$i]['isToggled'] = (bool) $state['columns'][$column['name']];
            }
        }
        $this->resetPage();
    }

    protected function exportTable(string $format): ?StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery();
        if ($query === null) {
            return null;
        }
        $limit = $format === 'pdf' ? self::PDF_LIMIT : self::EXPORT_LIMIT;
        $count = (clone $query)->count();
        if ($count > $limit) {
            Notification::make()->title(__('lists.too_many', ['count' => $count, 'limit' => $limit]))->danger()->send();

            return null;
        }
        $columns = array_values(array_filter($this->getTable()->getVisibleColumns(), fn (Column $c) => $c instanceof TextColumn || $c instanceof IconColumn));
        $rows = [];
        foreach ($query->get() as $record) {
            $rows[] = array_map(fn (Column $column) => $this->exportValue($column, $record), $columns);
        }
        $title = self::plain($this->getTitle());
        $list = new ListTable($title, array_map(fn (Column $c) => self::plain($c->getLabel()), $columns), $rows);
        Audit::record('table.exported', null, null, ['page' => $this->viewPage(), 'format' => $format, 'rows' => count($rows)]);
        $content = app(ListExport::class)->render($list, $format);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, ListExport::fileName(str($title)->ascii()->toString().'-'.now()->format('Y-m-d'), $format), ['Content-Type' => ListExport::TYPES[$format], 'Cache-Control' => 'private, no-store']);
    }

    private static function plain(string|Htmlable|null $text): string
    {
        return trim(html_entity_decode(strip_tags($text instanceof Htmlable ? $text->toHtml() : (string) $text), ENT_QUOTES));
    }

    private function exportValue(Column $column, Model $record): string
    {
        $column->record($record);
        $column->clearCachedState();
        $state = $column->getState();
        if ($column instanceof IconColumn) {
            return (string) __($state ? 'forms.yes' : 'forms.no');
        }
        $values = [];
        foreach (is_iterable($state) ? $state : [$state] as $item) {
            if ($item !== null && $item !== '') {
                $values[] = html_entity_decode(strip_tags((string) ($column instanceof TextColumn ? $column->formatState($item) : $item)), ENT_QUOTES);
            }
        }

        return trim(implode(', ', array_unique($values)));
    }
}

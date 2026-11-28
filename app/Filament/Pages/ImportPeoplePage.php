<?php

namespace App\Filament\Pages;

use App\Console\Commands\ImportPeopleCommand;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Support\InArea;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Import\PeopleImporter;
use App\Support\Import\WeblingConnector;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Import wizard (MI01–MI04): source (CSV/XLSX file or Webling), field assignment, dry run with errors and family
 * suggestions, import as batch (rollback in the list of import batches).
 *
 * @property-read Schema $form
 */
class ImportPeoplePage extends Page
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $slug = 'import/people';

    protected string $view = 'filament.pages.import-people';

    /** @var array<string, mixed> */
    public array $data = ['type' => 'file'];

    /** @var list<string> */
    public array $columns = [];

    /** @var array<string, mixed>|null */
    public ?array $plan = null;

    #[Locked]
    public string $cacheKey = '';

    public static function getNavigationLabel(): string
    {
        return __('import.title');
    }

    public function getTitle(): string
    {
        return __('import.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Permissions::allows($user, 'people', 'edit');
    }

    public function mount(): void
    {
        $this->cacheKey = 'import-wizard-'.Str::random(20);
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        $fieldSelects = array_map(fn (string $field) => Select::make('mapping.'.$field)->label($field)->options(fn () => array_combine($this->columns, $this->columns) ?: [])
            ->placeholder('–'), PeopleImporter::FIELDS);

        return $schema->statePath('data')->components([
            Section::make(__('import.step_source'))->columns(2)->schema([
                Radio::make('type')->label(__('import.source_type'))->options(['file' => __('import.type_file'), 'webling' => 'Webling'])->live()->required(),
                TextInput::make('source')->label(__('import.source'))->helperText(__('import.source_hint'))->regex('/^[a-z0-9_.-]+$/')->maxLength(60)
                    ->visible(fn (Get $get) => $get('type') === 'file'),
                FileUpload::make('file')->label(__('import.file'))->disk('local')->directory('imports')->maxSize(20480)
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->visible(fn (Get $get) => $get('type') === 'file'),
                TextInput::make('webling_url')->label(__('import.webling_url'))->placeholder('https://example.webling.eu')->url()
                    ->visible(fn (Get $get) => $get('type') === 'webling'),
                TextInput::make('webling_key')->label(__('import.webling_key'))->password()->revealable()->helperText(__('import.webling_key_hint'))
                    ->visible(fn (Get $get) => $get('type') === 'webling'),
            ]),
            Section::make(__('import.step_mapping'))->description(__('import.mapping_hint'))->columns(3)->schema($fieldSelects)
                ->visible(fn () => $this->columns !== []),
        ]);
    }

    /** Step 1: read the source, guess the field assignment. */
    public function read(): void
    {
        $data = $this->form->getState();
        try {
            if ($data['type'] === 'webling') {
                $this->columns = app(WeblingConnector::class)->properties((string) $data['webling_url'], (string) $data['webling_key']);
                $this->data['source'] = 'webling';
                Cache::put($this->cacheKey, $this->weblingRaw((string) $data['webling_url'], (string) $data['webling_key']), now()->addHour());
            } else {
                $path = (string) ($data['file'] ?? '');
                if ($path === '' || ($data['source'] ?? '') === '') {
                    Notification::make()->title(__('import.need_file'))->danger()->send();

                    return;
                }
                $raw = ImportPeopleCommand::readFile(Storage::disk('local')->path($path), $path);
                Storage::disk('local')->delete($path); // personal data are not kept as a file (MI09)
                $this->columns = array_keys($raw[0] ?? []);
                Cache::put($this->cacheKey, $raw, now()->addHour());
            }
        } catch (\Throwable $e) {
            Notification::make()->title(__('import.read_failed'))->body($e->getMessage())->danger()->send();

            return;
        }
        // Guess: a column named like a field, or like the Webling standard property.
        $mapping = [];
        foreach (PeopleImporter::FIELDS as $field) {
            $guess = collect($this->columns)->first(fn (string $c) => Str::lower($c) === $field || $c === (WeblingConnector::DEFAULT_MAPPING[$field] ?? null));
            $mapping[$field] = $guess;
        }
        $this->data['mapping'] = $mapping;
        $this->data['webling_key'] = null;
        $this->plan = null;
        Notification::make()->title(__('import.read_done', ['count' => count((array) Cache::get($this->cacheKey, []))]))->success()->send();
    }

    /** Step 2: dry run with the chosen assignment. */
    public function preview(): void
    {
        $this->plan = app(PeopleImporter::class)->plan($this->mappedRows(), (string) $this->data['source']);
    }

    /** Step 3: import as batch. */
    public function import(): void
    {
        $batch = app(PeopleImporter::class)->import($this->mappedRows(), (string) $this->data['source']);
        Cache::forget($this->cacheKey);
        $this->plan = null;
        $this->columns = [];
        Notification::make()->title(__('import.imported', ['batch' => $batch->id, 'created' => $batch->stats['created'] ?? 0, 'updated' => $batch->stats['updated'] ?? 0, 'skipped' => $batch->stats['skipped'] ?? 0]))->success()->persistent()->send();
        $this->redirect(ImportBatchResource::getUrl('index'));
    }

    /** @return list<array<string, mixed>> */
    private function mappedRows(): array
    {
        $raw = (array) Cache::get($this->cacheKey, []);
        $mapping = array_filter((array) ($this->data['mapping'] ?? []));
        $rows = [];
        foreach ($raw as $record) {
            $row = [];
            foreach ($mapping as $field => $column) {
                $row[$field] = $record[$column] ?? null;
            }
            if (isset($record['__id'])) {
                $row['source_id'] = $row['source_id'] ?? $record['__id'];
                if (isset($record['__groups']) && empty($row['groups'])) {
                    $row['groups'] = $record['__groups'];
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Webling members as flat records (property => value) with the member id and group names.
     *
     * @return list<array<string, mixed>>
     */
    private function weblingRaw(string $url, string $key): array
    {
        $identity = array_combine($this->columns, $this->columns) ?: [];
        $rows = app(WeblingConnector::class)->rows($url, $key, $identity);
        foreach ($rows as &$row) {
            $row['__id'] = $row['source_id'];
            $row['__groups'] = $row['groups'] ?? null;
        }

        return $rows;
    }
}

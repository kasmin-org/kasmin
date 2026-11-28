<?php

namespace App\Filament\Support;

use App\Support\Audit\Audit;
use App\Support\Settings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * A page of settings (S01): fields stored in system_meta, a search over the fields and one save button. Since B11 the
 * settings are on three pages – organisation (Administration), invoices (Accounting) and global settings
 * (Administration, administrators only).
 *
 * @property-read Schema $form
 */
abstract class SettingsPage extends Page
{
    protected string $view = 'filament.pages.organisation-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @return list<string> the settings on this page */
    abstract protected static function keys(): array;

    /** @return list<string> settings that are switches */
    protected static function switches(): array
    {
        return [];
    }

    /** The section for the audit log. */
    abstract protected static function section(): string;

    /** @return array<int, mixed> */
    abstract protected function sections(): array;

    /** @param  array<string, mixed>  $data */
    protected function filling(array &$data): void {}

    /** Turns the value of a field into what is stored. */
    protected function storing(string $key, mixed $value): mixed
    {
        return $value;
    }

    /** @param  array<string, mixed>  $data */
    protected function saved(array $data): void {}

    public function mount(): void
    {
        $data = [];
        foreach (static::keys() as $key) {
            $data[$key] = Settings::get($key);
        }
        foreach (static::switches() as $key) {
            $data[$key] = Settings::bool($key);
        }
        $this->filling($data);
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components($this->sections());
    }

    /** @return array{0: string, 1: string} icon and text of a hint */
    protected static function hint(string $key): array
    {
        return ['heroicon-m-question-mark-circle', __('admin.settings.'.$key.'_hint')];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (array_unique([...static::keys(), ...static::switches()]) as $key) {
            if (! array_key_exists($key, $data)) {
                continue; // sections this person may not change
            }
            $value = $data[$key];
            if (in_array($key, static::switches(), true)) {
                $value = $value ? '1' : '0';
            }
            $value = $this->storing($key, $value);
            Settings::set($key, $value === null ? null : (string) $value);
        }
        $this->saved($data);
        Audit::record('settings.updated', null, null, ['section' => static::section()]);
        Notification::make()->title(__('admin.settings.saved'))->success()->send();
    }
}

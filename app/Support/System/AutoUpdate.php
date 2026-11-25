<?php

namespace App\Support\System;

use App\Support\Audit\Audit;
use App\Support\Mail\Composer;
use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Automatic updates (UP03): a release is installed by the scheduler once it has been out for the configured number of
 * days (default 10), in the night (default 04:00, local time). Administrators get a notice two days before and one
 * afterwards with the changes. If a newer release appears in between, the planned update is cancelled (with a notice)
 * and the waiting time starts again for the newer one – so a release that is corrected shortly after is skipped.
 */
class AutoUpdate
{
    public const STATE = 'auto_update_state';

    /** Days before the update at which the notice is sent. */
    public const NOTICE_DAYS = 2;

    /** Hours after the configured time in which the update may still start. */
    public const WINDOW_HOURS = 3;

    public function __construct(private readonly Releases $releases, private readonly Updater $updater, private readonly SchemaGuard $schema, private readonly SystemNotifications $notifications) {}

    public function enabled(): bool
    {
        return Settings::bool('auto_update') && $this->updater->available();
    }

    /** Called by every scheduler run. */
    public function tick(): string
    {
        $state = $this->state();
        // The files were replaced by the last run: finish with the new code, then tell the administrators.
        if (($state['installing'] ?? null) !== null) {
            return $this->finish($state);
        }
        if (! $this->enabled()) {
            if ($state !== []) {
                $this->save([]);
            }

            return 'off';
        }
        $release = $this->releases->latest();
        if ($release === null || ! $this->releases->updateAvailable() || empty($release['published_at'])) {
            return 'nothing';
        }
        if (($state['version'] ?? null) !== $release['version']) {
            if (($state['announced_at'] ?? null) !== null) {
                $this->mail('cancelled', ['old' => (string) $state['version'], 'new' => $release['version'], 'date' => $this->dueAt($release)->isoFormat('LLL')]);
            }
            $state = ['version' => $release['version']];
            $this->save($state);
        }
        $due = $this->dueAt($release);
        if (($state['announced_at'] ?? null) === null && now()->gte($due->copy()->subDays(self::NOTICE_DAYS))) {
            $this->mail('notice', ['version' => $release['version'], 'date' => $due->isoFormat('LLL'), 'url' => $release['url']]);
            $this->save($state = ['announced_at' => now()->toIso8601String()] + $state);

            return 'announced';
        }
        if (now()->lt($due) || ! $this->inWindow() || ($state['failed_on'] ?? null) === now()->toDateString()) {
            return 'waiting';
        }

        return $this->install($release, $state);
    }

    /**
     * First time of day (settings) after the release has been out for the configured days.
     *
     * @param  array<string, mixed>  $release
     */
    public function dueAt(array $release): Carbon
    {
        [$hour, $minute] = $this->time();
        $earliest = Carbon::parse((string) $release['published_at'])->setTimezone(config('app.timezone'))->addDays(max(0, Settings::int('auto_update_days')));
        $due = $earliest->copy()->setTime($hour, $minute);

        return $due->lt($earliest) ? $due->addDay() : $due;
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $state
     */
    private function install(array $release, array $state): string
    {
        $from = (string) config('kasmin.version');
        try {
            $this->save(['installing' => $release['version'], 'from' => $from, 'started_at' => now()->toIso8601String()] + $state);
            $finish = $this->updater->run();
        } catch (Throwable $e) {
            report($e);
            $this->save(['failed_on' => now()->toDateString()] + $state);
            $this->mail('failed', ['version' => $release['version'], 'error' => $e->getMessage()]);
            Audit::record('system.auto_update_failed', null, null, ['version' => $release['version'], 'error' => $e->getMessage()], 'system');

            return 'failed';
        }
        // The new code only runs in a new request. If the server cannot call itself, the next scheduler run finishes.
        try {
            Http::timeout(120)->get($finish);
        } catch (Throwable) {
            File::put(storage_path('framework/kasmin-auto-update'), $release['version']);
        }

        return 'installed';
    }

    /** @param  array<string, mixed>  $state */
    private function finish(array $state): string
    {
        if ($this->schema->status(fresh: true) === SchemaStatus::UpgradeRequired) {
            Artisan::call('kasmin:upgrade', ['--no-backup' => true]); // the backup was made before the files were replaced
        }
        if ($this->schema->status(fresh: true) !== SchemaStatus::Ok) {
            return 'upgrade pending';
        }
        @unlink(storage_path('framework/kasmin-auto-update'));
        @unlink(storage_path('framework/kasmin-update.json'));
        $this->save([]);
        $version = (string) config('kasmin.version');
        if (version_compare($version, (string) $state['installing'], '<')) {
            return 'not installed'; // the files were rolled back; the failure was already reported
        }
        $this->mail('done', ['version' => $version, 'from' => (string) ($state['from'] ?? '')], self::changes($version));
        Audit::record('system.auto_updated', null, null, ['from' => $state['from'] ?? null, 'to' => $version], 'system');

        return 'done';
    }

    /** Section of the version in CHANGELOG.md, as simple HTML. */
    public static function changes(string $version): string
    {
        $changelog = is_file(base_path('CHANGELOG.md')) ? (string) file_get_contents(base_path('CHANGELOG.md')) : '';
        if (preg_match('/^## \['.preg_quote($version, '/').'\][^\n]*\n(.*?)(?=^## \[|\z)/ms', $changelog, $m) !== 1) {
            return '';
        }
        $html = '';
        $list = false;
        foreach (explode("\n", trim($m[1])) as $line) {
            $line = trim($line);
            $item = str_starts_with($line, '- ');
            if ($list && ! $item) {
                $html .= '</ul>';
                $list = false;
            }
            if ($item) {
                $html .= ($list ? '' : '<ul>').'<li>'.e(substr($line, 2)).'</li>';
                $list = true;
            } elseif (str_starts_with($line, '### ')) {
                $html .= '<h3>'.e(substr($line, 4)).'</h3>';
            } elseif ($line !== '') {
                $html .= '<p>'.e($line).'</p>';
            }
        }

        return $html.($list ? '</ul>' : '');
    }

    private function inWindow(): bool
    {
        [$hour, $minute] = $this->time();
        $start = now()->copy()->setTime($hour, $minute);

        return now()->between($start, $start->copy()->addHours(self::WINDOW_HOURS));
    }

    /** @return array{0: int, 1: int} */
    private function time(): array
    {
        $time = (string) Settings::get('auto_update_time');
        [$hour, $minute] = preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) === 1 ? [(int) $m[1], (int) $m[2]] : [4, 0];

        return [$hour, $minute];
    }

    /** @param  array<string, string>  $values */
    private function mail(string $kind, array $values, string $html = ''): void
    {
        foreach ($this->notifications->administrators() as $admin) {
            $locale = $admin->person->locale ?? 'en';
            $body = '<p>'.e(__('system.auto_update.'.$kind, $values, $locale)).'</p>'.$html.'<p><a href="'.e(url('/admin/system')).'">'.e(__('system.update.back', [], $locale)).'</a></p>';
            app(Composer::class)->queueRaw($admin->email, $admin->name, 'Kasmin: '.__('system.auto_update.'.$kind.'_subject', $values, $locale), $body, $locale);
        }
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        Settings::flush();

        return (array) json_decode((string) Settings::get(self::STATE), true);
    }

    /** @param  array<string, mixed>  $state */
    private function save(array $state): void
    {
        Settings::set(self::STATE, $state === [] ? null : (string) json_encode($state));
    }
}

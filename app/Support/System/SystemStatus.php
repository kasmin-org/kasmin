<?php

namespace App\Support\System;

use App\Support\Backup\Backups;
use App\Support\Backup\RemoteBackup;
use App\Support\Install\Requirements;
use App\Support\Mail\Sender;
use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks for the status page (ST01) and the system notifications (SN02–SN04).
 */
class SystemStatus
{
    public const HEARTBEAT_KEY = 'scheduler_last_run';

    public const CRON_STALE_MINUTES = 15;

    public function __construct(
        private readonly PhpSupport $php,
        private readonly Releases $releases,
        private readonly Requirements $requirements,
        private readonly SchemaGuard $schema,
    ) {}

    /** Called on every run of the scheduler. */
    public static function heartbeat(): void
    {
        DB::table('system_meta')->updateOrInsert(['key' => self::HEARTBEAT_KEY], ['value' => now()->toIso8601String(), 'updated_at' => now(), 'created_at' => now()]);
    }

    public function lastSchedulerRun(): ?Carbon
    {
        try {
            $value = DB::table('system_meta')->where('key', self::HEARTBEAT_KEY)->value('value');

            return is_string($value) ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function cronMissing(): bool
    {
        $last = $this->lastSchedulerRun();

        return $last === null || $last->lt(now()->subMinutes(self::CRON_STALE_MINUTES));
    }

    public static function runsInDocker(): bool
    {
        return config('kasmin.runtime') === 'docker' || is_file('/.dockerenv');
    }

    /**
     * @return list<array<string, mixed>> each with key, status (ok, warning, error, info), label, detail and an optional url
     */
    public function checks(): array
    {
        $checks = [];
        $eol = $this->php->endOfSupport();
        $checks[] = [
            'key' => 'php', 'label' => 'PHP '.PHP_VERSION,
            'status' => $this->php->isUnsupported() ? 'error' : ($this->php->isLowestSupported() ? 'warning' : 'ok'),
            'detail' => $eol ? __('system.status.php_eol', ['date' => $eol->isoFormat('LL')]) : __('system.status.php_unknown'),
        ];
        foreach ($this->requirements->check() as $req) {
            if (! $req['ok']) {
                $checks[] = ['key' => 'requirement', 'label' => $req['label'], 'status' => 'error', 'detail' => __('system.status.missing')];
            }
        }
        $items = $this->requirements->details();
        $missing = collect($items)->where('ok', false);
        $checks[] = [
            'key' => 'requirements', 'label' => __('system.status.requirements'), 'items' => $items, 'url' => self::docsUrl('admin/hosting'),
            'status' => $this->requirements->passes() ? ($missing->isNotEmpty() ? 'warning' : 'ok') : 'error',
            'detail' => $missing->isEmpty() ? __('system.requirements.all', ['count' => count($items)]) : __('system.requirements.some', ['names' => $missing->pluck('name')->implode(', ')]),
        ];
        $memory = Requirements::memoryLimitMb();
        $checks[] = [
            'key' => 'memory', 'label' => __('system.requirements.memory'), 'url' => self::docsUrl('admin/hosting'),
            'status' => $memory === null || $memory >= Requirements::MEMORY_MB ? 'ok' : 'warning',
            'detail' => __($memory === null ? 'system.requirements.memory_unlimited' : ($memory >= Requirements::MEMORY_MB ? 'system.requirements.memory_ok' : 'system.requirements.memory_low'), ['mb' => $memory, 'needed' => Requirements::MEMORY_MB])
                .' '.__(Requirements::moreMemory() ? 'system.requirements.memory_more' : 'system.requirements.memory_fixed'),
        ];
        $last = $this->lastSchedulerRun();
        $checks[] = [
            'key' => 'cron', 'label' => __('system.status.cron'), 'status' => $this->cronMissing() ? 'error' : 'ok',
            'detail' => $last ? __('system.status.cron_last', ['time' => $last->diffForHumans()]) : __('system.status.cron_never'),
            'url' => self::docsUrl('admin/hosting'),
        ];
        $queued = DB::table('outgoing_mails')->where('status', 'queued')->where('created_at', '<', now()->subMinutes(30))->count();
        $checks[] = ['key' => 'queue', 'label' => __('system.status.queue'), 'status' => $queued > 0 ? 'warning' : 'ok', 'detail' => __('system.status.queue_old', ['count' => $queued])];
        $checks[] = ['key' => 'database', 'label' => __('system.status.database'), 'status' => $this->schema->status(fresh: true) === SchemaStatus::Ok ? 'ok' : 'error', 'detail' => self::databaseVersion().' · '.__('system.schema.versions', ['code' => $this->schema->codeVersion(), 'database' => $this->schema->databaseVersion() ?? '–'])];
        $backup = app(Backups::class)->all()[0] ?? null;
        $checks[] = [
            'key' => 'backup', 'label' => __('system.status.backup'), 'status' => $backup === null || $backup['at']->lt(now()->subDays(2)) ? 'warning' : 'ok',
            'detail' => $backup === null ? __('system.status.backup_none') : __('system.status.backup_last', ['time' => $backup['at']->diffForHumans(), 'size' => number_format($backup['size'] / 1048576, 1)]),
            'url' => self::docsUrl('admin/upgrading'),
        ];
        $remote = RemoteBackup::configured() ? RemoteBackup::state() : null;
        $checks[] = [
            'key' => 'backup_remote', 'label' => __('system.backup.remote'), 'url' => self::docsUrl('admin/upgrading'),
            'status' => $remote === null ? 'warning' : ($remote['ok'] === false || ($remote['at'] !== null && Carbon::parse($remote['at'])->lt(now()->subDays(2))) ? 'error' : ($remote['ok'] === null ? 'info' : 'ok')),
            'detail' => $remote === null ? __('system.backup.remote_off') : ($remote['ok'] === null ? __('system.backup.remote_never') : __($remote['ok'] ? 'system.backup.remote_ok' : 'system.backup.remote_failed', ['time' => Carbon::parse((string) $remote['at'])->diffForHumans(), 'error' => (string) $remote['error']])),
        ];
        if (Sender::testAddress() !== null) {
            $checks[] = ['key' => 'test_mode', 'label' => __('admin.settings.mail_test_address'), 'status' => 'warning', 'detail' => Sender::testAddress()];
        }
        $latest = $this->releases->latest();
        $checks[] = [
            'key' => 'version', 'label' => 'Kasmin '.config('kasmin.version'),
            'status' => $this->releases->updateAvailable() ? 'warning' : ($latest ? 'ok' : 'info'),
            'detail' => $latest ? __('system.status.latest', ['version' => $latest['version']]) : __('system.status.latest_unknown'),
            'url' => $latest['url'] ?? null,
        ];

        return array_values(array_filter($checks, fn ($c) => $c['key'] !== 'requirements' || $c['status'] === 'ok'));
    }

    /**
     * Raises or resolves the system notifications (SN02–SN04).
     */
    public function notify(SystemNotifications $notifications): void
    {
        $docs = self::docsUrl('admin/hosting');
        if ($this->cronMissing()) {
            $notifications->raise('cron_missing', 'danger',
                ['de' => 'Cronjob nicht eingerichtet', 'en' => 'Cron job not configured'],
                ['de' => 'Kasmin braucht einen Cronjob, der alle 5 Minuten läuft (Mailversand, Erinnerungen, Automatisierungen). Seit über '.self::CRON_STALE_MINUTES.' Minuten wurde keiner ausgeführt. Die Dokumentation zeigt, wie Sie ihn einrichten.',
                    'en' => 'Kasmin needs a cron job running every 5 minutes (sending mails, reminders, automations). None has run for more than '.self::CRON_STALE_MINUTES.' minutes. The documentation shows how to set it up.'], $docs);
        } else {
            $notifications->resolve('cron_missing');
        }

        $branch = $this->php->currentBranch();
        $eol = $this->php->endOfSupport();
        if ($this->php->isLowestSupported() || $this->php->isUnsupported()) {
            $date = $eol?->format('d.m.Y') ?? '?';
            $notifications->raise('php_lowest:'.$branch, $this->php->isUnsupported() ? 'danger' : 'warning',
                ['de' => "PHP {$branch} bitte aktualisieren", 'en' => "Please update PHP {$branch}"],
                ['de' => "PHP {$branch} ist die älteste noch unterstützte PHP-Version und erhält nur bis {$date} Sicherheitsupdates. Bitte stellen Sie bei Ihrem Hosting auf eine neuere PHP-Version um – neuere Kasmin-Versionen werden PHP {$branch} bald nicht mehr unterstützen.",
                    'en' => "PHP {$branch} is the oldest PHP version still supported and receives security updates only until {$date}. Please switch to a newer PHP version at your hosting – newer Kasmin versions will soon no longer support PHP {$branch}."],
                self::docsUrl('development/versioning'));
        } else {
            $notifications->resolve('php_lowest:');
        }

        $latest = $this->releases->latest();
        if ($latest !== null && $this->releases->updateAvailable()) {
            $notifications->raise('kasmin_update:'.$latest['version'], 'info',
                ['de' => "Kasmin {$latest['version']} ist verfügbar", 'en' => "Kasmin {$latest['version']} is available"],
                ['de' => "Eine neue Kasmin-Version ist verfügbar ({$latest['version']}, installiert: ".config('kasmin.version').'). Die Aktualisierung starten Sie auf der Systemseite.',
                    'en' => "A new Kasmin version is available ({$latest['version']}, installed: ".config('kasmin.version').'). Start the update on the system page.'],
                $latest['url']);
        }
    }

    /** Kind and version of the database server, e.g. "MariaDB 11.4.2". */
    public static function databaseVersion(): string
    {
        $driver = DB::connection()->getDriverName();
        try {
            $version = (string) (DB::selectOne($driver === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v')->v ?? '');
        } catch (Throwable) {
            $version = '';
        }
        $name = match (true) {
            stripos($version, 'mariadb') !== false => 'MariaDB', $driver === 'sqlite' => 'SQLite', default => 'MySQL',
        };

        return trim($name.' '.preg_replace('/-MariaDB.*$/i', '', $version));
    }

    public static function docsUrl(string $page): string
    {
        return Docs::url($page);
    }
}

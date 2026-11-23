<?php

namespace App\Support\System;

use App\Support\Install\Requirements;
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

    /** Called by the scheduler every minute. */
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
        return env('KASMIN_RUNTIME') === 'docker' || is_file('/.dockerenv');
    }

    /**
     * @return list<array{key: string, status: string, label: string, detail: string, url?: string}>
     *                                                                                           status: ok, warning, error, info
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
        $checks[] = ['key' => 'requirements', 'label' => __('system.status.requirements'), 'status' => $this->requirements->passes() ? 'ok' : 'error', 'detail' => ''];
        $last = $this->lastSchedulerRun();
        $checks[] = [
            'key' => 'cron', 'label' => __('system.status.cron'), 'status' => $this->cronMissing() ? 'error' : 'ok',
            'detail' => $last ? __('system.status.cron_last', ['time' => $last->diffForHumans()]) : __('system.status.cron_never'),
            'url' => self::docsUrl('admin/hosting'),
        ];
        $queued = DB::table('outgoing_mails')->where('status', 'queued')->where('created_at', '<', now()->subMinutes(30))->count();
        $checks[] = ['key' => 'queue', 'label' => __('system.status.queue'), 'status' => $queued > 0 ? 'warning' : 'ok', 'detail' => __('system.status.queue_old', ['count' => $queued])];
        $checks[] = ['key' => 'database', 'label' => __('system.status.database'), 'status' => $this->schema->status(fresh: true) === SchemaStatus::Ok ? 'ok' : 'error', 'detail' => DB::connection()->getDriverName().' · '.__('system.schema.versions', ['code' => $this->schema->codeVersion(), 'database' => $this->schema->databaseVersion() ?? '–'])];
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
                ['de' => 'Kasmin braucht einen Cronjob, der jede Minute läuft (Mailversand, Erinnerungen, Automatisierungen). Seit über '.self::CRON_STALE_MINUTES.' Minuten wurde keiner ausgeführt. Die Dokumentation zeigt, wie Sie ihn einrichten.',
                    'en' => 'Kasmin needs a cron job running every minute (sending mails, reminders, automations). None has run for more than '.self::CRON_STALE_MINUTES.' minutes. The documentation shows how to set it up.'], $docs);
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

    public static function docsUrl(string $page): string
    {
        return 'https://www.kasmin.org/docs/latest/'.$page.'/';
    }
}

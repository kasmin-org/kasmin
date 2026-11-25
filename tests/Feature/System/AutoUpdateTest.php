<?php

namespace Tests\Feature\System;

use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\Settings;
use App\Support\System\AutoUpdate;
use App\Support\System\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Automatic updates (UP03): waiting time, notices, newer releases, result.
 */
class AutoUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
        config(['kasmin.version' => '1.0.0', 'app.timezone' => 'UTC']);
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]);
        Settings::set('auto_update', '1');
        $this->travelTo(Carbon::parse('2027-03-01 12:00'));
    }

    private function release(string $version, string $published): void
    {
        Cache::forever('kasmin.latest_release', ['tag' => 'v'.$version, 'version' => $version, 'url' => 'https://example.org/'.$version, 'zip' => 'z', 'sha256' => 's', 'requirements' => null, 'published_at' => $published]);
    }

    /** @return array<int, string> subjects of the mails so far */
    private function subjects(): array
    {
        return OutgoingMail::query()->orderBy('id')->pluck('subject')->all();
    }

    private function updater(bool $works = true, int $times = 1): void
    {
        $updater = Mockery::mock(Updater::class);
        $updater->shouldReceive('available')->andReturn(true);
        $works ? $updater->shouldReceive('run')->times($times)->andReturnUsing(function () {
            config(['kasmin.version' => '1.1.0']); // the files are replaced

            return 'https://kasmin.test/system/update/finish?token=x';
        }) : $updater->shouldReceive('run')->andThrow(new RuntimeException('Checksum mismatch'));
        $this->app->instance(Updater::class, $updater);
        $this->app->forgetInstance(AutoUpdate::class);
    }

    private function tick(): string
    {
        return app(AutoUpdate::class)->tick();
    }

    public function test_nothing_happens_when_it_is_switched_off_or_in_docker(): void
    {
        $this->release('1.1.0', '2027-01-01T00:00:00Z');
        Settings::set('auto_update', '0');
        $results = [$this->tick()];
        Settings::set('auto_update', '1');
        $updater = Mockery::mock(Updater::class);
        $updater->shouldReceive('available')->andReturn(false);
        $updater->shouldNotReceive('run');
        $this->app->instance(Updater::class, $updater);
        $results[] = $this->tick();
        $this->assertSame(['off', 'off'], $results);
        $this->assertSame([], $this->subjects());
    }

    public function test_due_after_the_full_days_at_the_time_of_day_in_the_local_time_zone(): void
    {
        $auto = app(AutoUpdate::class);
        $this->assertSame('2027-03-12 04:00', $auto->dueAt(['published_at' => '2027-03-01T10:00:00Z'])->format('Y-m-d H:i'));
        $this->assertSame('2027-03-11 04:00', $auto->dueAt(['published_at' => '2027-03-01T03:00:00Z'])->format('Y-m-d H:i'));
        Settings::set('auto_update_days', '3');
        Settings::set('auto_update_time', '22:30');
        config(['app.timezone' => 'Europe/Vienna']);
        $this->assertSame('2027-03-04 22:30 CET', $auto->dueAt(['published_at' => '2027-03-01T10:00:00Z'])->format('Y-m-d H:i T'));
    }

    public function test_notice_two_days_before_then_update_in_the_night_and_a_mail_with_the_changes(): void
    {
        Http::fake();
        $this->release('1.1.0', '2027-03-01T10:00:00Z'); // due 12 March 04:00
        $this->updater();
        $this->assertSame('waiting', $this->tick());
        $this->travelTo(Carbon::parse('2027-03-10 04:05'));
        $this->assertSame('announced', $this->tick());
        $this->assertSame('waiting', $this->tick());
        $this->assertSame(['Kasmin: Update to 1.1.0 on 12 March 2027 04:00'], $this->subjects());

        $this->travelTo(Carbon::parse('2027-03-12 03:55'));
        $this->assertSame('waiting', $this->tick());
        $this->travelTo(Carbon::parse('2027-03-12 09:00'));
        $this->assertSame('waiting', $this->tick(), 'not in the middle of the day');
        $this->travelTo(Carbon::parse('2027-03-13 04:05'));
        $this->assertSame('installed', $this->tick());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/system/update/finish'));

        $this->assertSame('done', $this->tick());
        $mail = OutgoingMail::query()->latest('id')->firstOrFail();
        $this->assertSame('Kasmin: Updated to 1.1.0', $mail->subject);
        $this->assertStringContainsString('from 1.0.0 to 1.1.0', $mail->html);
        $this->assertSame('nothing', $this->tick());
        $this->assertCount(2, $this->subjects());
    }

    public function test_a_newer_release_cancels_the_announced_update_and_the_waiting_time_starts_again(): void
    {
        $this->release('1.1.0', '2027-03-01T10:00:00Z');
        $this->updater(times: 0);
        $this->travelTo(Carbon::parse('2027-03-10 12:00'));
        $this->tick();
        $this->release('1.1.1', '2027-03-11T08:00:00Z'); // due 22 March 04:00
        $this->assertSame('waiting', $this->tick());
        $this->assertSame(['Kasmin: Update to 1.1.0 on 12 March 2027 04:00', 'Kasmin: Update to 1.1.0 cancelled'], $this->subjects());
        $this->assertStringContainsString('1.1.1 is planned for 22 March 2027 04:00', OutgoingMail::query()->latest('id')->firstOrFail()->html);
        $this->travelTo(Carbon::parse('2027-03-12 04:05'));
        $this->assertSame('waiting', $this->tick(), 'the old date does not count any more');

        // A newer release before any notice was sent: no cancel mail.
        $this->release('1.1.2', '2027-03-12T00:00:00Z');
        $this->tick();
        $this->assertCount(2, $this->subjects());
    }

    public function test_a_failed_update_is_reported_and_tried_again_the_next_night(): void
    {
        $this->release('1.1.0', '2027-02-01T10:00:00Z');
        $this->updater(false);
        $this->travelTo(Carbon::parse('2027-03-02 04:10'));
        $this->assertSame('announced', $this->tick());
        $this->assertSame('failed', $this->tick());
        $this->assertSame('waiting', $this->tick(), 'only once per night');
        $this->assertStringContainsString('Checksum mismatch', OutgoingMail::query()->latest('id')->firstOrFail()->html);
        $this->travelTo(Carbon::parse('2027-03-03 04:10'));
        $this->assertSame('failed', $this->tick());
        $this->assertSame('1.0.0', config('kasmin.version'));
    }

    public function test_changes_of_a_version_are_read_from_the_changelog(): void
    {
        $html = AutoUpdate::changes('0.19.0');
        $this->assertStringContainsString('<h3>Added</h3>', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringNotContainsString('0.18.0', $html);
        $this->assertSame('', AutoUpdate::changes('9.9.9'));
    }
}

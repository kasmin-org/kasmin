<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Automations\Approvals;
use App\Support\Automations\Engine;
use App\Support\Install\Defaults;
use App\Support\Mail\MailLinks;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Approvers get one mail with everything that waits for them, at a time that can be set, instead of one mail per
 * request (AT06). Wanted after the replay of two years showed 95 single mails for one approver.
 */
class ApprovalSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-03-01 10:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->board = $this->approver('board@example.org');
    }

    private function approver(string $email): User
    {
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'board')->value('id')]);

        return User::query()->create(['name' => 'Rowan Board', 'email' => $email, 'password' => 'x', 'person_id' => $person->id]);
    }

    private function request(string $automation, string $label): AutomationRun
    {
        $run = Automation::query()->where('key', $automation)->firstOrFail()->runs()->create([
            'trigger' => 'daily', 'plan' => [['key' => 'x:'.$label, 'label' => $label]], 'status' => 'pending_approval', 'expires_at' => now()->addDays(7),
        ]);
        app(Approvals::class)->request($run);

        return $run;
    }

    /** @return Collection<int, OutgoingMail> */
    private function mails(string $to = 'board@example.org')
    {
        return OutgoingMail::query()->where('to_address', $to)->orderBy('id')->get();
    }

    public function test_requests_wait_for_the_summary_and_everything_since_the_last_one_is_in_it(): void
    {
        $this->assertSame(['07:00'], Approvals::summaryTimes(), 'the default');
        $first = $this->request('payment_reminders', 'Remind family Ash');
        $second = $this->request('waitlist_offers', 'Offer a place to Bay Birch');
        app(Engine::class)->tick();
        $this->assertCount(0, $this->mails(), 'nothing before the time of the summary');

        $this->travelTo(Carbon::parse('2027-03-02 07:05'));
        app(Engine::class)->tick();
        $mail = $this->mails()->sole();
        $this->assertSame('2 approvals are waiting for you', $mail->subject);
        $this->assertStringContainsString('Remind family Ash', $mail->html);
        $this->assertStringContainsString('Offer a place to Bay Birch', $mail->html);
        $this->assertStringNotContainsString('Still open from earlier', $mail->html);

        // Every request has its own personal link, made when the mail is sent.
        $sent = MailLinks::resolve($mail)['html'];
        $this->assertStringNotContainsString(MailLinks::PLACEHOLDER, $sent);
        preg_match_all('#/approvals/([A-Za-z0-9]{48})#', $sent, $m);
        $tokens = array_values(array_unique($m[1]));
        $this->assertCount(2, $tokens);
        $this->assertSame([$first->id, $second->id], array_map(fn (string $t) => app(Approvals::class)->findByToken($t)?->automation_run_id, $tokens));

        // Later the same day and the next morning: nothing new, no mail.
        app(Engine::class)->tick();
        $this->travelTo(Carbon::parse('2027-03-03 07:05'));
        app(Engine::class)->tick();
        $this->assertCount(1, $this->mails());

        // A new request and one decided in between: only what is new and open.
        app(Engine::class)->decide($first, $this->board, false);
        $this->request('safety_sheet_reminders', 'Remind family Cedar');
        $this->travelTo(Carbon::parse('2027-03-04 07:05'));
        app(Engine::class)->tick();
        $latest = $this->mails()->last() ?? throw new \RuntimeException('no mail');
        $this->assertSame('One approval is waiting for you', $latest->subject);
        $this->assertStringContainsString('Remind family Cedar', (string) $latest->html);
        $this->assertStringNotContainsString('Remind family Ash', (string) $latest->html);
        $this->assertStringNotContainsString('Bay Birch', (string) $latest->html, 'the open one from before is not repeated every day');

        // Half-way to its end the still open request is named once more, in the summary.
        $this->travelTo(Carbon::parse('2027-03-06 07:05'));
        app(Engine::class)->tick();
        $reminder = $this->mails()->last();
        $this->assertStringContainsString('Still open from earlier', (string) $reminder?->html);
        $this->assertStringContainsString('Bay Birch', (string) $reminder?->html);
        $this->travelTo(Carbon::parse('2027-03-07 07:05'));
        app(Engine::class)->tick();
        $this->assertStringNotContainsString('Bay Birch', (string) $this->mails()->last()?->html, 'reminded once; the next summary only names the other request that is half-way now');
    }

    public function test_the_time_can_be_set_also_several_times_a_day_or_switched_off(): void
    {
        Settings::set('approval_summary_time', '12:00, 18:30');
        $this->assertSame(['12:00', '18:30'], Approvals::summaryTimes());
        $this->request('payment_reminders', 'Remind family Ash');
        $this->travelTo(Carbon::parse('2027-03-01 11:59'));
        $this->assertSame(0, app(Approvals::class)->sendSummaries());
        $this->travelTo(Carbon::parse('2027-03-01 12:01'));
        $this->assertSame(1, app(Approvals::class)->sendSummaries());
        $this->request('waitlist_offers', 'Offer a place to Bay Birch');
        $this->travelTo(Carbon::parse('2027-03-01 18:29'));
        $this->assertSame(0, app(Approvals::class)->sendSummaries());
        $this->travelTo(Carbon::parse('2027-03-01 18:40'));
        $this->assertSame(1, app(Approvals::class)->sendSummaries());
        $this->assertCount(2, $this->mails());

        // Switched off: every request is a mail of its own, at once, as before.
        Settings::set('approval_summary_time', '');
        $this->request('safety_sheet_reminders', 'Remind family Cedar');
        $this->assertSame('Approval needed: Safety sheet reminders', $this->mails()->last()?->subject);
        $this->assertSame(0, app(Approvals::class)->sendSummaries());
    }

    public function test_every_approver_gets_only_what_is_open_for_them(): void
    {
        $other = $this->approver('second@example.org');
        $run = $this->request('payment_reminders', 'Remind family Ash');
        $run->approvals()->create(['user_id' => $other->id]); // several approvals needed, this one has given theirs
        app(Approvals::class)->sendSummaries(); // the first look only notes the time
        $this->travelTo(Carbon::parse('2027-03-02 07:05'));
        $this->assertSame(1, app(Approvals::class)->sendSummaries());
        $this->assertCount(1, $this->mails());
        $this->assertCount(0, $this->mails('second@example.org'));
    }
}

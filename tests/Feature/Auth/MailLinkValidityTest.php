<?php

namespace Tests\Feature\Auth;

use App\Models\Event;
use App\Models\LoginToken;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\LoginLinks;
use App\Support\Install\Defaults;
use App\Support\Mail\MailLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Links in mails are valid until the end of the event the mail is about (B14). */
class MailLinkValidityTest extends TestCase
{
    use RefreshDatabase;

    private Person $guardian;

    private Person $child;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-10 10:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->guardian = Person::factory()->create(['first_name' => 'Alex', 'locale' => 'en']);
        $this->guardian->contactPoints()->create(['type' => 'email', 'value' => 'alex@example.org', 'is_primary' => true]);
        $this->user = User::query()->create(['name' => 'Alex', 'email' => 'alex@example.org', 'password' => 'x', 'person_id' => $this->guardian->id]);
        $this->child = Person::factory()->create(['first_name' => 'Kim', 'birth_date' => '2016-05-05']);
        $this->child->guardians()->attach($this->guardian->id);
    }

    private function event(string $start, string $end, string $status = 'open'): Event
    {
        return Event::query()->create(['title' => 'Camp', 'starts_at' => $start, 'ends_at' => $end, 'status' => $status]);
    }

    private function signUp(Event $event, string $status = 'registered'): Participation
    {
        return Participation::query()->create(['event_id' => $event->id, 'person_id' => $this->child->id, 'role_key' => 'child', 'status' => $status, 'registered_at' => now()]);
    }

    /** @return array{0: string, 1: string} the sent html and the token */
    private function send(?Event $event): array
    {
        $mail = OutgoingMail::query()->create([
            'person_id' => $this->guardian->id, 'event_id' => $event?->id, 'to_address' => 'alex@example.org', 'subject' => 'Info',
            'html' => '<p><a href="'.MailLinks::placeholder().'">Portal</a></p><p>'.e(__('mail.link_valid_until', ['date' => MailLinks::UNTIL_PLACEHOLDER])).'</p>',
            'text' => 'Portal: '.MailLinks::placeholder()."\n\n".__('mail.link_valid_until', ['date' => MailLinks::UNTIL_PLACEHOLDER]),
        ]);
        $html = MailLinks::resolve($mail)['html'];
        preg_match('#/login/link/([A-Za-z0-9]+)#', $html, $m);

        return [$html, $m[1] ?? ''];
    }

    public function test_a_mail_about_an_event_the_child_is_signed_up_for_is_valid_until_its_end(): void
    {
        $event = $this->event('2027-08-01 10:00', '2027-08-08 16:00');
        $this->signUp($event, 'waitlist');
        [$html, $token] = $this->send($event);

        $model = LoginToken::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        $this->assertSame('2027-08-08 16:00', $model->expires_at->format('Y-m-d H:i'));
        $this->assertSame($event->id, $model->event_id);
        $this->assertStringContainsString('valid until August 8, 2027', $html);
        $this->assertStringNotContainsString(MailLinks::UNTIL_PLACEHOLDER, $html);

        $this->travelTo(Carbon::parse('2027-07-20 10:00'));
        $this->assertNotNull(app(LoginLinks::class)->findByToken($token));
    }

    public function test_never_shorter_than_30_days_never_longer_than_a_year_and_30_days_without_an_event(): void
    {
        $links = app(LoginLinks::class);
        $soon = $this->event('2027-01-12 10:00', '2027-01-14 10:00');
        $this->signUp($soon);
        $this->assertSame(30 * 24 * 60, $links->mailLinkMinutes($this->guardian, $soon));

        $far = $this->event('2028-07-01 10:00', '2028-07-08 10:00');
        $this->signUp($far);
        $this->assertSame(365 * 24 * 60, $links->mailLinkMinutes($this->guardian, $far));

        $notSignedUp = $this->event('2027-08-01 10:00', '2027-08-08 10:00');
        $this->assertSame(30 * 24 * 60, $links->mailLinkMinutes($this->guardian, $notSignedUp));
        $this->assertSame(30 * 24 * 60, $links->mailLinkMinutes($this->guardian, null));

        [$html] = $this->send(null);
        $this->assertStringContainsString('valid until February 9, 2027', $html);
    }

    public function test_cancellation_cancelled_event_and_end_of_guardianship_shorten_the_link_to_30_days(): void
    {
        $links = app(LoginLinks::class);
        $event = $this->event('2027-08-01 10:00', '2027-08-08 16:00');
        $participation = $this->signUp($event);
        [, $cancelled] = $this->send($event);
        [, $eventCancelled] = $this->send($event);
        [, $guardianship] = $this->send($event);

        // Within the first 30 days nothing changes.
        $participation->update(['status' => 'cancelled']);
        $this->travel(29)->days();
        $this->assertNotNull($links->findByToken($cancelled));
        $this->travel(2)->days();
        $this->assertNull($links->findByToken($cancelled), 'cancelled: back to 30 days');

        $participation->update(['status' => 'registered']);
        $this->assertNotNull($links->findByToken($eventCancelled));
        $event->update(['status' => 'cancelled']);
        $this->assertNull($links->findByToken($eventCancelled), 'event cancelled');

        $event->update(['status' => 'open']);
        $this->assertNotNull($links->findByToken($guardianship));
        DB::table('guardianships')->where('guardian_id', $this->guardian->id)->update(['ends_on' => now()->subDay()->toDateString()]);
        $this->assertNull($links->findByToken($guardianship), 'guardianship ended');
    }

    public function test_all_links_of_older_mails_can_be_made_invalid_in_the_portal(): void
    {
        $event = $this->event('2027-08-01 10:00', '2027-08-08 16:00');
        $this->signUp($event);
        [, $token] = $this->send($event);
        $this->actingAs($this->user)->withSession(['login_scope' => 'full'])
            ->post('/portal/profile/links')->assertRedirect()->assertSessionHas('status');
        $this->assertNull(app(LoginLinks::class)->findByToken($token));
        $this->assertDatabaseHas('audit_events', ['action' => 'login.mail_links_revoked']);
    }

    public function test_previews_show_a_hint_instead_of_the_date(): void
    {
        $preview = MailLinks::preview('<p>'.e(__('mail.link_valid_until', ['date' => MailLinks::UNTIL_PLACEHOLDER])).'</p>');
        $this->assertStringContainsString(__('mail.link_valid_until_preview'), $preview);
        $this->assertStringNotContainsString('[[', $preview);
    }
}

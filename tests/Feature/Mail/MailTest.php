<?php

namespace Tests\Feature\Mail;

use App\Models\Consent;
use App\Models\Event;
use App\Models\Group;
use App\Models\Household;
use App\Models\MailAccount;
use App\Models\Mailing;
use App\Models\MailTemplate;
use App\Models\NotificationEvent;
use App\Models\NotificationSubscription;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\SenderProfile;
use App\Models\User;
use App\Support\Events\Registrations;
use App\Support\Install\Defaults;
use App\Support\Mail\Mailings;
use App\Support\Mail\Notifier;
use App\Support\Mail\Placeholders;
use App\Support\Mail\Sender;
use App\Support\Mail\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\TestCase;

class MailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
    }

    /** @return array{0: Person, 1: Person, 2: Household} guardian, child, household */
    private function family(): array
    {
        $guardian = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Example', 'locale' => 'de', 'birth_date' => now()->subYears(40)]);
        $guardian->contactPoints()->create(['type' => 'email', 'value' => 'robin@example.org', 'purposes' => ['login'], 'is_primary' => true]);
        User::query()->create(['name' => 'Robin', 'email' => 'robin@example.org', 'password' => 'x', 'person_id' => $guardian->id]);
        $child = Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Example', 'birth_date' => now()->addMonths(6)->subYears(10)]);
        $child->guardians()->attach($guardian->id);
        $household = Household::factory()->create(['name' => 'Example']);
        $household->members()->attach($guardian->id, ['role' => 'guardian']);
        $household->members()->attach($child->id, ['role' => 'child']);

        return [$guardian, $child, $household];
    }

    public function test_sandbox_blocks_code_and_unknown_placeholders(): void
    {
        $renderer = app(TemplateRenderer::class);
        $this->assertSame([], $renderer->validate('Hi {{ recipient.first_name }}', '<p>{{ salutation }} {% if children|length > 0 %}x{% endif %}</p>', null));
        $this->assertNotSame([], $renderer->validate('x', '{{ unknown.placeholder }}', null));
        $this->assertNotSame([], $renderer->validate('x', "{{ include('secret') }}", null));
        $this->assertNotSame([], $renderer->validate('x', '{% macro m() %}{% endmacro %}', null));
        $html = $renderer->renderStrings('s', '<p>{{ recipient.first_name }}</p>', null, ['recipient' => ['first_name' => '<script>x</script>']] + Placeholders::sample())['html'];
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_salutation_is_formal_for_adults_and_informal_for_children(): void
    {
        [$guardian, $child] = $this->family();
        $p = app(Placeholders::class);
        $this->assertSame('Guten Tag Robin Example,', $p->salutation($guardian, 'de'));
        $this->assertSame('Hallo Kim,', $p->salutation($child, 'de'));
        $this->assertSame('Hello Kim,', $p->salutation($child, 'en'));
    }

    public function test_registration_sends_a_confirmation_with_the_waitlist_position(): void
    {
        [, $child] = $this->family();
        $event = Event::query()->create(['title' => 'Summer camp', 'starts_at' => now()->addMonths(6), 'ends_at' => now()->addMonths(6)->addWeek(), 'status' => 'open']);
        $event->roles()->create(['role_key' => 'child', 'capacity' => 0, 'min_age' => 8, 'max_age' => 13]);

        app(Registrations::class)->register($event, $child);

        $mail = OutgoingMail::query()->where('to_address', 'robin@example.org')->firstOrFail();
        $this->assertStringContainsString('Guten Tag Robin Example', $mail->html);
        $this->assertStringContainsString('Platz 1 von 1', $mail->html);
        $this->assertStringContainsString('/mail/preferences/', $mail->html);
        $this->assertSame('queued', $mail->status);
        $this->assertTrue(NotificationEvent::query()->where('topic', 'waitlist')->exists());
    }

    public function test_sender_sends_queued_mails_and_respects_rate_limits(): void
    {
        $account = MailAccount::query()->create(['name' => 'Office', 'smtp_host' => 'smtp.example.org', 'is_default' => true, 'rate_per_minute' => 1]);
        $profile = SenderProfile::query()->create(['name' => 'Office', 'from_name' => 'Camp', 'from_address' => 'office@example.org', 'mail_account_id' => $account->id]);
        foreach (['a@example.org', 'b@example.org'] as $to) {
            OutgoingMail::query()->create(['sender_profile_id' => $profile->id, 'to_address' => $to, 'subject' => 'S', 'html' => '<p>x</p>', 'text' => 'x']);
        }
        // Use the array transport instead of a real SMTP server.
        $sender = new class extends Sender
        {
            public function transport(?MailAccount $account): TransportInterface
            {
                return new NullTransport;
            }
        };

        $first = $sender->process();
        $this->assertSame(1, $first['sent']);
        $this->assertSame(1, $first['deferred']);
        $sent = OutgoingMail::query()->where('status', 'sent')->firstOrFail();
        $this->assertStringEndsWith('@example.org', (string) $sent->message_id);
    }

    public function test_mailing_routes_to_guardians_once_per_family_and_respects_opt_outs(): void
    {
        [$guardian, $child] = $this->family();
        $sibling = Person::factory()->create(['first_name' => 'Sam', 'last_name' => 'Example']);
        $sibling->guardians()->attach($guardian->id);
        $other = Person::factory()->create();
        $otherGuardian = Person::factory()->create();
        $otherGuardian->contactPoints()->create(['type' => 'email', 'value' => 'other@example.org', 'is_primary' => true]);
        $other->guardians()->attach($otherGuardian->id);
        $group = Group::query()->create(['name' => 'Camp']);
        $group->people()->attach([$child->id, $sibling->id, $other->id]);

        $template = MailTemplate::query()->where('key', 'newsletter')->firstOrFail();
        $mailing = Mailing::query()->create(['name' => 'News', 'mail_template_id' => $template->id, 'recipients' => ['group_ids' => [$group->id]]]);
        Consent::query()->create(['person_id' => $otherGuardian->id, 'kind' => 'newsletter', 'granted' => false]);

        $plan = app(Mailings::class)->plan($mailing);
        $this->assertCount(1, $plan['mails']);
        $this->assertCount(2, $plan['mails'][0]['children']);
        $this->assertSame('opt_out', $plan['excluded'][0]['reason']);

        $this->assertSame(1, app(Mailings::class)->prepare($mailing));
        $this->assertSame('held', OutgoingMail::query()->firstOrFail()->status);
        $this->assertSame(1, app(Mailings::class)->release($mailing));
        $this->assertSame('queued', OutgoingMail::query()->firstOrFail()->status);
    }

    public function test_preference_links_are_signed_and_need_a_button_press(): void
    {
        [$guardian] = $this->family();
        $url = URL::temporarySignedRoute('mail.preferences', now()->addDay(), ['person' => $guardian->public_id]);

        $this->get('/mail/preferences/'.$guardian->public_id)->assertForbidden();
        $this->get($url)->assertOk();
        $this->assertSame(0, Consent::query()->count());

        $this->post($url, ['choice' => 'newsletter_off'])->assertRedirect();
        $this->assertFalse((bool) Consent::query()->where('kind', 'newsletter')->value('granted'));
        $this->post($url, ['choice' => 'deletion_request']);
        $this->assertTrue(NotificationEvent::query()->where('topic', 'deletion_requests')->exists());
    }

    public function test_notifications_immediately_and_as_digest(): void
    {
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x']);
        $board = User::query()->create(['name' => 'Board', 'email' => 'board@example.org', 'password' => 'x']);
        NotificationSubscription::query()->create(['user_id' => $admin->id, 'topic' => 'registrations', 'frequency' => 'immediate']);
        NotificationSubscription::query()->create(['user_id' => $board->id, 'topic' => 'registrations', 'frequency' => 'daily']);

        app(Notifier::class)->event('registrations', 'New sign-up: Robin Example');
        $this->assertSame(1, OutgoingMail::query()->where('to_address', 'admin@example.org')->count());
        $this->assertSame(0, OutgoingMail::query()->where('to_address', 'board@example.org')->count());

        $this->assertSame(1, app(Notifier::class)->digests());
        $this->assertStringContainsString('Robin Example', OutgoingMail::query()->where('to_address', 'board@example.org')->firstOrFail()->html);
        $this->assertSame(0, app(Notifier::class)->digests());
    }
}

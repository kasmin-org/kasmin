<?php

namespace Tests\Feature\Mail;

use App\Mail\ConfirmSignUpMail;
use App\Models\Event;
use App\Models\Person;
use App\Support\Install\Defaults;
use App\Support\Mail\Composer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Texts that read wrong – found by reading the mails of two replayed camp years as they arrived in a mailbox.
 */
class MailTextsTest extends TestCase
{
    use RefreshDatabase;

    private function guardian(): Person
    {
        $person = Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Ash', 'birth_date' => '1985-01-01', 'locale' => 'de']);
        $person->contactPoints()->create(['type' => 'email', 'value' => 'kim@example.org', 'is_primary' => true]);

        return $person;
    }

    public function test_event_information_has_no_lines_without_a_value(): void
    {
        app(Defaults::class)->install('de');
        $event = Event::query()->create(['title' => 'Wochenende', 'starts_at' => '2027-01-15 17:00', 'ends_at' => '2027-01-17 14:00', 'status' => 'open']);
        $mail = app(Composer::class)->queue('event_information', $this->guardian(), ['event' => $event]);
        $this->assertNotNull($mail);
        $this->assertStringContainsString('Beginn:', $mail->html);
        $this->assertStringNotContainsString('Ort:', $mail->html);
        $this->assertStringNotContainsString('Treffpunkt:', $mail->html);

        $event->update(['location' => 'Mistelbach']);
        $with = app(Composer::class)->queue('event_information', $this->guardian(), ['event' => $event->refresh()]);
        $this->assertStringContainsString('Ort: Mistelbach', strip_tags((string) $with?->html));
    }

    public function test_several_children_are_named_with_and(): void
    {
        app(Defaults::class)->install('de');
        $event = Event::query()->create(['title' => 'Sommerlager', 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open']);
        $children = array_map(fn (string $name) => Person::factory()->create(['first_name' => $name, 'last_name' => 'Ash', 'birth_date' => '2016-01-01']), ['Ann', 'Ben', 'Cleo']);
        $three = strip_tags((string) app(Composer::class)->queue('safety_sheet_reminder', $this->guardian(), ['event' => $event, 'children' => $children])?->html);
        $this->assertStringContainsString('von Ann, Ben und Cleo fehlt', $three);
        $one = strip_tags((string) app(Composer::class)->queue('safety_sheet_reminder', $this->guardian(), ['event' => $event, 'children' => [$children[0]]])?->html);
        $this->assertStringContainsString('von Ann fehlt', $one);
    }

    public function test_the_sign_up_mail_addresses_adults_with_their_full_name(): void
    {
        Mail::fake();
        $this->openSignUp();
        $this->post('/register', ['first_name' => 'Kim', 'last_name' => 'Ash', 'email' => 'kim@example.org', 'locale' => 'de', 'privacy' => '1'])->assertSessionHasNoErrors();
        Mail::assertSent(ConfirmSignUpMail::class, fn (ConfirmSignUpMail $mail) => $mail->name === 'Kim Ash');
    }
}

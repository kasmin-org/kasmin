<?php

namespace Tests\Feature\Mail;

use App\Filament\Pages\OrganisationSettingsPage;
use App\Filament\Resources\BadAddresses\Pages\ListBadAddresses;
use App\Filament\Resources\Mailings\Pages\ReviewMailing;
use App\Models\ContactPoint;
use App\Models\Mailing;
use App\Models\MailTemplate;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\Mail\BadAddresses;
use App\Support\Mail\MailBody;
use App\Support\Mail\MailImages;
use App\Support\Mail\Mailings;
use App\Support\Mail\MailRetention;
use App\Support\Mail\Sender;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Bad addresses, images, review and pause of mailings, retention of the mail log (MS05, MT02, MM05, MM08, ML05).
 */
class MailOperationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->actingAs(User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]))->withSession(['two_factor_passed' => true]);
    }

    private function person(string $email): Person
    {
        $person = Person::factory()->create(['locale' => 'en', 'birth_date' => '1985-05-05']);
        $person->contactPoints()->create(['type' => 'email', 'value' => $email, 'is_primary' => true]);

        return $person;
    }

    private function mailing(Person ...$people): Mailing
    {
        $template = MailTemplate::query()->where('key', 'safety_sheet_thanks')->firstOrFail();

        return Mailing::query()->create(['name' => 'News', 'mail_template_id' => $template->id, 'routing' => 'person', 'recipients' => ['person_ids' => array_map(fn (Person $p) => $p->id, $people)]]);
    }

    public function test_permanent_errors_are_recognised_and_temporary_ones_are_not(): void
    {
        $this->assertTrue(BadAddresses::permanent(new RuntimeException('Expected response code "250" but got code "550", with message "550 5.1.1 User unknown".')));
        $this->assertTrue(BadAddresses::permanent(new RuntimeException('550 No such user here')));
        $this->assertFalse(BadAddresses::permanent(new RuntimeException('450 4.2.0 Greylisted, try again')));
        $this->assertFalse(BadAddresses::permanent(new RuntimeException('550 5.7.1 Message rejected as spam')));
        $this->assertFalse(BadAddresses::permanent(new RuntimeException('Connection could not be established with host 10.5.1.1')));
        $this->assertTrue(BadAddresses::onlyDelayed('Delayed Mail', ''));
        $this->assertTrue(BadAddresses::onlyDelayed('Delivery Status Notification', "Action: delayed\nStatus: 4.4.1"));
        $this->assertFalse(BadAddresses::onlyDelayed('Undelivered Mail', "Action: failed\nStatus: 5.1.1"));
    }

    public function test_bad_addresses_are_listed_left_out_and_cleared(): void
    {
        $bad = $this->person('bad@example.org');
        $good = $this->person('good@example.org');
        $mailing = $this->mailing($bad, $good);
        $mail = OutgoingMail::query()->create(['to_address' => 'bad@example.org', 'subject' => 'x', 'html' => 'x', 'text' => 'x', 'person_id' => $bad->id]);
        app(BadAddresses::class)->mark($mail, '550 User unknown');
        $point = ContactPoint::query()->where('value', 'bad@example.org')->firstOrFail();
        $this->assertNotNull($point->bounced_at);

        $plan = app(Mailings::class)->plan($mailing);
        $this->assertCount(1, $plan['mails']);
        $this->assertSame('bad_address', $plan['excluded'][0]['reason']);

        Livewire::test(ListBadAddresses::class)->assertOk()->assertSee('bad@example.org')->callTableAction('works', $point);
        $this->assertNull($point->fresh()?->bounced_at);

        app(BadAddresses::class)->mark($mail, 'again');
        $point = ContactPoint::query()->findOrFail($point->id);
        $point->update(['value' => 'new@example.org']);
        $this->assertNull($point->fresh()?->bounced_at, 'a new address is not bad');
    }

    public function test_review_page_through_pause_and_resume(): void
    {
        $mailing = $this->mailing($this->person('a@example.org'), $this->person('b@example.org'));
        app(Mailings::class)->prepare($mailing);
        $page = Livewire::test(ReviewMailing::class, ['record' => $mailing->id])->assertOk()->assertSee('Mail 1 of 2')->assertSee('a@example.org')->call('go', 1)->assertSee('b@example.org')->call('go', 5)->assertSee('Mail 2 of 2');
        app(Mailings::class)->send($mailing->refresh());
        $page->call('pause');
        $this->assertSame(2, OutgoingMail::query()->where('status', 'paused')->count());
        $this->artisan('kasmin:mail:send');
        $this->assertSame('paused', $mailing->refresh()->status);
        $page->call('resume');
        $this->assertSame(2, OutgoingMail::query()->where('status', 'queued')->count());
        $page->call('cancel');
        $this->assertSame('cancelled', $mailing->refresh()->status);
        $page->call('resume')->assertForbidden();
    }

    public function test_editing_one_mail_keeps_the_layout(): void
    {
        $mailing = $this->mailing($this->person('a@example.org'));
        app(Mailings::class)->prepare($mailing);
        $mail = OutgoingMail::query()->sole();
        $new = MailBody::set($mail->html, '<p>Changed</p>');
        $this->assertStringContainsString('<!DOCTYPE html>', $new);
        $this->assertSame('<p>Changed</p>', MailBody::get($new));
        $this->assertStringContainsString('/mail/preferences/', $new);
    }

    public function test_images_are_embedded_in_the_mail(): void
    {
        Storage::fake('local');
        $file = MailImages::store(UploadedFile::fake()->image('logo.png', 10, 10));
        Settings::set('mail_logo', MailImages::path($file));
        $this->get(MailImages::url($file))->assertOk();
        $this->get('/mail-images/unknown.png')->assertNotFound();
        $mailing = $this->mailing($this->person('a@example.org'));
        app(Mailings::class)->prepare($mailing);
        $mail = OutgoingMail::query()->sole();
        $this->assertStringContainsString('/mail-images/'.$file, $mail->html);
        $email = app(Sender::class)->build($mail, null, null);
        $this->assertStringContainsString('cid:', (string) $email->getHtmlBody());
        $this->assertStringNotContainsString('/mail-images/', (string) $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
    }

    public function test_the_logo_stays_when_the_settings_are_saved_again_and_can_be_replaced(): void
    {
        Storage::fake('local');
        $page = Livewire::test(OrganisationSettingsPage::class)->set('data.mail_logo', UploadedFile::fake()->image('a.png', 40, 10))->call('save')->assertHasNoErrors();
        $first = (string) Settings::get('mail_logo');
        $this->assertTrue(MailImages::exists(basename($first)), 'stored as '.$first);
        $page->call('save')->assertHasNoErrors();
        $this->assertSame($first, Settings::get('mail_logo'), 'saved twice on the same page');
        Livewire::test(OrganisationSettingsPage::class)->call('save')->assertHasNoErrors();
        $this->assertSame($first, Settings::get('mail_logo'), 'opened and saved again');
        Livewire::test(OrganisationSettingsPage::class)->set('data.mail_logo', [])->set('data.mail_logo', UploadedFile::fake()->image('b.png', 40, 10))->call('save')->assertHasNoErrors();
        $second = (string) Settings::get('mail_logo');
        $this->assertNotSame($first, $second, 'removed and uploaded again');
        $this->assertTrue(MailImages::exists(basename($second)), 'replaced by '.$second);
    }

    public function test_retention_of_the_mail_log(): void
    {
        $make = fn (string $status, ?array $attachments) => OutgoingMail::query()->create(['to_address' => 'a@example.org', 'subject' => 'x', 'html' => 'x', 'text' => 'x', 'status' => $status, 'attachments' => $attachments]);
        $make('sent', null);
        $make('sent', [['invoice_id' => 1]]);
        $make('queued', null);
        OutgoingMail::query()->update(['created_at' => now()->subYears(3)]);
        $this->assertSame(0, app(MailRetention::class)->run(), 'off by default');
        Settings::set('retention_mail_years', '2');
        $this->assertSame(1, app(MailRetention::class)->run());
        $this->assertSame(2, OutgoingMail::query()->count());
        OutgoingMail::query()->update(['created_at' => now()->subYears(8)]);
        $this->assertSame(1, app(MailRetention::class)->run());
        $this->assertSame('queued', OutgoingMail::query()->sole()->status);
    }
}

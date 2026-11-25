<?php

namespace Tests\Feature\Mail;

use App\Models\LoginToken;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\Mail\Composer;
use App\Support\Mail\MailLinks;
use App\Support\Mail\Sender;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailLinksTest extends TestCase
{
    use RefreshDatabase;

    private function mail(): OutgoingMail
    {
        app(Defaults::class)->install('en');
        $person = Person::factory()->create(['locale' => 'en']);
        $person->contactPoints()->create(['type' => 'email', 'value' => 'robin@example.org', 'is_primary' => true, 'verified_at' => now()]);
        User::query()->create(['name' => 'Robin', 'email' => 'robin@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $mail = app(Composer::class)->queue('safety_sheet_thanks', $person, ['children' => []]);
        $this->assertNotNull($mail);

        return $mail;
    }

    public function test_stored_mails_have_no_personal_link_but_sent_mails_do(): void
    {
        $mail = $this->mail();
        $this->assertTrue(MailLinks::has($mail->html) && MailLinks::has($mail->text));
        $this->assertSame(0, LoginToken::query()->count());
        $this->get('/login/link/'.MailLinks::PLACEHOLDER)->assertOk();
        $this->post('/login/link/'.MailLinks::PLACEHOLDER)->assertRedirect('/login');
        $this->assertGuest();

        $email = app(Sender::class)->build($mail, null, null);
        $this->assertSame(1, LoginToken::query()->where('scope', 'limited')->count());
        $this->assertMatchesRegularExpression('#/login/link/[A-Za-z0-9]{48}"#', (string) $email->getHtmlBody());
        preg_match('#/login/link/[A-Za-z0-9]{48}#', (string) $email->getTextBody(), $match);
        $this->assertNotEmpty($match);
        $this->assertTrue(MailLinks::has((string) $mail->fresh()?->html), 'nothing is stored when sending');
        $this->post($match[0] ?? '')->assertRedirect(route('portal'));
        $this->assertAuthenticated();
    }

    public function test_no_personal_link_in_the_test_mode_or_to_a_removed_address(): void
    {
        $mail = $this->mail();
        Settings::set('mail_test_address', 'test@example.org');
        $this->assertStringNotContainsString('/login/link/', (string) app(Sender::class)->build($mail, null, null)->getHtmlBody());
        Settings::set('mail_test_address', '');
        $mail->forceFill(['to_address' => 'other@example.org']);
        $this->assertStringNotContainsString('/login/link/', (string) app(Sender::class)->build($mail, null, null)->getHtmlBody());
        $this->assertSame(0, LoginToken::query()->count());
    }

    public function test_links_in_mails_already_stored_are_removed(): void
    {
        $mail = $this->mail();
        $token = str_repeat('a1B2', 12);
        $mail->forceFill(['html' => '<a href="https://example.org/login/link/'.$token.'">x</a>', 'text' => 'https://example.org/login/link/'.$token])->save();
        (require database_path('migrations/2026_12_09_000100_remove_login_links_from_stored_mails.php'))->up();
        $mail->refresh();
        $this->assertStringNotContainsString($token, $mail->html.$mail->text);
        $this->assertTrue(MailLinks::has($mail->html) && MailLinks::has($mail->text));
    }
}

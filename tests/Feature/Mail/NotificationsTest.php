<?php

namespace Tests\Feature\Mail;

use App\Filament\Pages\NotificationsPage;
use App\Models\Event;
use App\Models\NotificationSubscription;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\Mail\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Notifications for people with administrative roles (NT01–NT05).
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
    }

    private function user(string $role): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    private function mails(User $user): int
    {
        return OutgoingMail::query()->where('to_address', $user->email)->count();
    }

    public function test_everyone_chooses_the_topics_of_the_own_rights(): void
    {
        $treasurer = $this->user('treasurer');
        $this->actingAs($treasurer)->withSession(['two_factor_passed' => true]);
        Livewire::test(NotificationsPage::class)->assertOk()->assertSee('Payments')->assertDontSee('Safety sheets')
            ->set('choices', ['payments' => 'immediate', 'safety_updates' => 'immediate', 'profile_updates' => 'nonsense'])->call('save');
        $this->assertSame(['payments' => 'immediate'], NotificationSubscription::query()->where('user_id', $treasurer->id)->where('frequency', '!=', 'off')->pluck('frequency', 'topic')->all());

        $notifier = app(Notifier::class);
        $notifier->event('payments', 'Payment of 10 €');
        $notifier->event('safety_updates', 'Safety sheet changed');
        $this->assertSame(1, $this->mails($treasurer));
        Livewire::test(NotificationsPage::class)->assertSee('Payment of 10 €')->assertDontSee('Safety sheet changed');
    }

    public function test_rights_are_checked_again_when_something_is_sent(): void
    {
        $treasurer = $this->user('treasurer');
        NotificationSubscription::query()->create(['user_id' => $treasurer->id, 'topic' => 'payments', 'frequency' => 'immediate']);
        NotificationSubscription::query()->create(['user_id' => $treasurer->id, 'topic' => 'safety_updates', 'frequency' => 'immediate']);
        $notifier = app(Notifier::class);
        $notifier->event('safety_updates', 'x');
        $this->assertSame(0, $this->mails($treasurer));
        $treasurer->person?->roleAssignments()->toBase()->update(['ends_on' => now()->subDay()->toDateString()]);
        $notifier->event('payments', 'x');
        $this->assertSame(0, $this->mails($treasurer), 'the role has ended');
    }

    public function test_roles_for_own_events_get_nothing_about_the_whole_organisation(): void
    {
        $leader = $this->user('camp_leader');
        $event = Event::query()->create(['title' => 'Camp', 'status' => 'open', 'starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(37)]);
        $leader->person?->roleAssignments()->toBase()->update(['event_id' => $event->id]);
        NotificationSubscription::query()->create(['user_id' => $leader->id, 'topic' => 'registrations', 'frequency' => 'immediate']);
        app(Notifier::class)->event('registrations', 'Somebody registered for another event');
        $this->assertSame(0, $this->mails($leader));
        $this->actingAs($leader)->withSession(['two_factor_passed' => true])->get('/admin/notifications')->assertForbidden();
    }

    public function test_administrators_do_not_miss_deletion_requests_and_can_switch_them_off(): void
    {
        $admin = $this->user('administrator');
        $board = $this->user('board');
        app(Notifier::class)->event('deletion_requests', 'Please delete my data');
        $this->assertSame([1, 0], [$this->mails($admin), $this->mails($board)]);
        app(Notifier::class)->event('registrations', 'x');
        $this->assertSame(1, $this->mails($admin), 'everything else only if chosen');

        $this->actingAs($admin)->withSession(['two_factor_passed' => true]);
        Livewire::test(NotificationsPage::class)->assertSet('choices.deletion_requests', 'immediate')->set('choices.deletion_requests', 'off')->call('save');
        app(Notifier::class)->event('deletion_requests', 'Another');
        $this->assertSame(1, $this->mails($admin));
    }

    public function test_digest_starts_when_it_is_chosen(): void
    {
        $board = $this->user('board');
        $notifier = app(Notifier::class);
        $notifier->event('registrations', 'Before');
        $this->travel(1)->minutes();
        $this->actingAs($board)->withSession(['two_factor_passed' => true]);
        Livewire::test(NotificationsPage::class)->set('choices.registrations', 'daily')->call('save');
        $this->travel(1)->minutes();
        $notifier->event('registrations', 'After');
        $this->travel(1)->minutes();
        $notifier->digests();
        $mail = OutgoingMail::query()->where('to_address', $board->email)->sole();
        $this->assertStringContainsString('After', $mail->html);
        $this->assertStringNotContainsString('Before', $mail->html);
    }
}

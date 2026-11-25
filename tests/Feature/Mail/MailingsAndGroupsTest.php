<?php

namespace Tests\Feature\Mail;

use App\Filament\Resources\Groups\Pages\CreateGroup;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\RelationManagers\PeopleRelationManager;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Consent;
use App\Models\Event;
use App\Models\EventDocument;
use App\Models\EventSeries;
use App\Models\Group;
use App\Models\Mailing;
use App\Models\MailTemplate;
use App\Models\MembershipType;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Automations\Engine;
use App\Support\Install\Defaults;
use App\Support\Mail\Composer;
use App\Support\Mail\Mailings;
use App\Support\Mail\Sender;
use App\Support\People\GroupMembers;
use App\Support\RuntimeSettings;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MailingsAndGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-03-01 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        config(['mail.to' => null]);
        parent::tearDown();
    }

    private function person(string $name, ?string $born = null, bool $mail = true): Person
    {
        $person = Person::factory()->create(['first_name' => $name, 'last_name' => 'Ash', 'birth_date' => $born, 'locale' => 'en']);
        if ($mail) {
            $person->contactPoints()->create(['type' => 'email', 'value' => strtolower($name).'@example.org', 'is_primary' => true]);
        }

        return $person;
    }

    /** @param array<string, mixed> $recipients */
    private function mailing(array $recipients, string $template = 'newsletter'): Mailing
    {
        return Mailing::query()->create(['name' => 'M', 'mail_template_id' => MailTemplate::query()->where('key', $template)->value('id'), 'recipients' => $recipients]);
    }

    /** @return list<string> */
    private function addresses(Mailing $mailing): array
    {
        $list = array_map(fn (array $m) => (string) $m['to']->primaryEmail(), app(Mailings::class)->plan($mailing)['mails']);
        sort($list);

        return $list;
    }

    private function admin(string $role = 'administrator'): User
    {
        auth()->forgetUser();
        $person = $this->person('Role'.$role);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->where('person_id', $person->id)->firstOrFail();
    }

    public function test_own_registrations_are_not_mailed_to_the_guardians(): void
    {
        $event = Event::query()->create(['title' => 'Weekend', 'starts_at' => '2027-07-01', 'ends_at' => '2027-07-03', 'status' => 'open']);
        $guardian = $this->person('Guardian');
        [$own, $normal] = [$this->person('Own', '2011-01-01'), $this->person('Normal', '2012-01-01')];
        foreach ([$own, $normal] as $child) {
            $child->guardians()->attach($guardian->id);
            Participation::query()->create(['event_id' => $event->id, 'person_id' => $child->id, 'role_key' => 'guest', 'status' => 'registered', 'registered_at' => now(), 'registered_by_id' => $child->is($own) ? $own->id : $guardian->id]);
        }
        $mailing = $this->mailing(['event_id' => $event->id]);
        $this->assertSame(['guardian@example.org', 'own@example.org'], $this->addresses($mailing));
        $plan = app(Mailings::class)->plan($mailing)['mails'];
        $toGuardian = collect($plan)->first(fn (array $m) => $m['to']->is($guardian));
        $this->assertSame(['Normal'], array_map(fn (Person $p) => $p->first_name, $toGuardian['children'] ?? []));
        Settings::set('own_registrations_visible', '1');
        $this->assertSame(['guardian@example.org'], $this->addresses($mailing));
    }

    public function test_the_latest_choice_counts(): void
    {
        $ann = $this->person('Ann');
        $mailing = $this->mailing(['person_ids' => [$ann->id]]);
        Consent::query()->create(['person_id' => $ann->id, 'kind' => 'newsletter', 'granted' => false]);
        $this->assertSame([], $this->addresses($mailing));
        Consent::query()->create(['person_id' => $ann->id, 'kind' => 'newsletter', 'granted' => true]);
        $this->assertSame(['ann@example.org'], $this->addresses($mailing));
        Consent::query()->create(['person_id' => $ann->id, 'kind' => 'newsletter', 'granted' => false]);
        $this->assertSame([], $this->addresses($mailing));
    }

    public function test_ended_members_get_nothing_and_subgroups_count(): void
    {
        $parent = Group::query()->create(['name' => 'Parent', 'kind' => 'manual']);
        $sub = Group::query()->create(['name' => 'Sub', 'kind' => 'manual', 'parent_id' => $parent->id]);
        $parent->update(['parent_id' => $sub->id]); // a loop must not hang
        [$ann, $ben] = [$this->person('Ann'), $this->person('Ben')];
        GroupMembers::join($parent, $ann->id, 'manual');
        GroupMembers::join($sub, $ben->id, 'manual');
        $mailing = $this->mailing(['group_ids' => [$parent->id]]);
        $this->assertSame(['ann@example.org', 'ben@example.org'], $this->addresses($mailing));
        GroupMembers::leave($parent, $ann->id);
        $this->assertSame(['ben@example.org'], $this->addresses($mailing));
        $this->assertSame(1, DB::table('group_memberships')->where('person_id', $ann->id)->whereNotNull('ends_on')->count(), 'history stays');
        GroupMembers::join($parent, $ann->id, 'manual');
        $this->assertSame(2, DB::table('group_memberships')->where('person_id', $ann->id)->count());
    }

    public function test_dynamic_groups(): void
    {
        $series = EventSeries::query()->create(['name' => 'Camp', 'type' => 'camp']);
        $next = Event::query()->create(['title' => '2027', 'event_series_id' => $series->id, 'starts_at' => '2027-08-01', 'ends_at' => '2027-08-08', 'status' => 'open']);
        $past = Event::query()->create(['title' => '2026', 'event_series_id' => $series->id, 'starts_at' => '2026-08-01', 'ends_at' => '2026-08-08', 'status' => 'finished']);
        $fits = $this->person('Fits', '2013-07-31');     // 14 at the camp, still 13 today
        $young = $this->person('Young', '2013-08-02');
        $never = $this->person('Never', '2013-01-01');
        foreach ([$fits, $young] as $p) {
            Participation::query()->create(['event_id' => $past->id, 'person_id' => $p->id, 'role_key' => 'child', 'status' => 'attended', 'registered_at' => now()]);
        }
        $group = Group::query()->create(['name' => 'Candidates', 'kind' => 'dynamic', 'event_id' => $next->id, 'filter' => ['match' => 'all', 'rules' => [
            ['field' => 'age', 'min' => 14, 'max' => 15], ['field' => 'events', 'value' => 'child', 'min' => 1],
        ]]]);
        $members = app(GroupMembers::class);
        $members->update($group);
        $this->assertSame([$fits->id], $group->people()->pluck('people.id')->all());
        $group->update(['filter' => ['match' => 'any', 'rules' => [['field' => 'age', 'min' => 14, 'max' => 15], ['field' => 'events', 'value' => 'child', 'min' => 1]]]]);
        $members->update($group);
        $this->assertEqualsCanonicalizing([$fits->id, $young->id, $never->id], $group->people()->pluck('people.id')->all());
        $type = MembershipType::query()->where('key', 'regular')->firstOrFail();
        $never->memberships()->create(['membership_type_id' => $type->id, 'starts_on' => '2026-01-01']);
        $group->update(['filter' => ['match' => 'all', 'rules' => [['field' => 'age', 'min' => 14], ['field' => 'membership', 'value' => $type->id, 'not' => true]]]]);
        $this->assertContains($fits->id, $members->matching($group)->all());
        $this->assertNotContains($never->id, $members->matching($group)->all());
        $group->update(['filter' => ['match' => 'all', 'rules' => []]]);
        $this->assertSame([], $members->matching($group)->all(), 'no rules is nobody, not everybody');
        $members->updateAll();
        $this->assertSame(0, $group->people()->count());
        $this->assertGreaterThan(0, DB::table('group_memberships')->where('group_id', $group->id)->whereNotNull('ends_on')->count());
    }

    public function test_event_groups_follow_the_registrations(): void
    {
        $event = Event::query()->create(['title' => 'E', 'starts_at' => '2027-07-01', 'ends_at' => '2027-07-03', 'status' => 'open']);
        $ann = $this->person('Ann');
        $p = Participation::query()->create(['event_id' => $event->id, 'person_id' => $ann->id, 'role_key' => 'child', 'status' => 'waitlist', 'registered_at' => now()]);
        $group = Group::query()->create(['name' => 'G', 'kind' => 'event', 'event_id' => $event->id, 'filter' => ['statuses' => ['registered']]]);
        $mailing = $this->mailing(['group_ids' => [$group->id]]);
        $this->assertSame([], $this->addresses($mailing));
        $p->update(['status' => 'registered']);
        $this->assertSame(['ann@example.org'], $this->addresses($mailing), 'recalculated for the mailing');
    }

    public function test_groups_in_the_admin_interface(): void
    {
        $ann = $this->person('Ann', '2010-01-01');
        $this->actingAs($this->admin());
        Livewire::test(CreateGroup::class)->fillForm(['name' => 'Choir', 'kind' => 'manual'])->call('create')->assertHasNoFormErrors();
        $group = Group::query()->where('name', 'Choir')->sole();
        $component = fn () => Livewire::test(PeopleRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditGroup::class]);
        $component()->callTableAction('add', data: ['person_id' => $ann->id])->assertHasNoTableActionErrors();
        $this->assertSame(1, $group->people()->count());
        $component()->callTableAction('remove', $ann);
        $this->assertSame(0, $group->people()->count());
        Livewire::test(EditGroup::class, ['record' => $group->id])->fillForm(['kind' => 'dynamic', 'filter' => ['match' => 'all', 'rules' => [['field' => 'age', 'min' => 15, 'max' => 20]]]])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$ann->id], $group->refresh()->people()->pluck('people.id')->all());
        $component()->assertTableActionHidden('add');
    }

    public function test_large_mailings_need_an_approval(): void
    {
        Settings::set('mailing_approval_above', '1');
        $mailing = $this->mailing(['person_ids' => [$this->person('Ann')->id, $this->person('Ben')->id]]);
        app(Mailings::class)->prepare($mailing);
        $board = $this->admin('board');
        $this->assertNull(app(Mailings::class)->release($mailing, $board));
        $this->assertSame('waiting', $mailing->refresh()->status);
        $this->assertSame(0, OutgoingMail::query()->where('mailing_id', $mailing->id)->where('status', 'queued')->count());
        $run = AutomationRun::query()->sole();
        $this->assertSame('pending_approval', $run->status);
        app(Engine::class)->decide($run, $board, true);
        $this->assertSame(2, OutgoingMail::query()->where('mailing_id', $mailing->id)->where('status', 'queued')->count());

        Settings::set('mailing_approval_above', '50');
        $small = $this->mailing(['person_ids' => [$this->person('Cem')->id]]);
        app(Mailings::class)->prepare($small);
        $this->assertSame(1, app(Mailings::class)->release($small, $board));
        $this->assertTrue(Automation::query()->where('key', 'mailing_release')->exists());
    }

    public function test_static_attachments_and_documents(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('mail-attachments/list.pdf', 'PDFCONTENT');
        Storage::disk('local')->put('event-documents/d.pdf', 'DOCCONTENT');
        $event = Event::query()->create(['title' => 'E', 'starts_at' => '2027-07-01', 'ends_at' => '2027-07-03', 'status' => 'open']);
        $document = EventDocument::query()->create(['event_id' => $event->id, 'title' => 'Packing list', 'path' => 'event-documents/d.pdf']);
        MailTemplate::query()->where('key', 'newsletter')->firstOrFail()->update(['attachments' => ['mail-attachments/list.pdf']]);
        $mailing = $this->mailing(['person_ids' => [$this->person('Ann')->id]]);
        $mailing->update(['document_ids' => [$document->id]]);
        app(Mailings::class)->prepare($mailing);
        $mail = OutgoingMail::query()->where('mailing_id', $mailing->id)->sole();
        $email = app(Sender::class)->build($mail, null, null);
        $this->assertSame(['list.pdf', 'packing-list.pdf'], array_map(fn ($a) => $a->getFilename(), $email->getAttachments()));
        Storage::disk('local')->delete('mail-attachments/list.pdf');
        $this->assertFalse(app(Sender::class)->send($mail, null), 'never sent without a promised file');
    }

    public function test_test_mode_redirects_every_mail(): void
    {
        $ann = $this->person('Ann');
        $mail = app(Composer::class)->queue('newsletter', $ann);
        $this->assertNotNull($mail);
        $this->assertSame('ann@example.org', app(Sender::class)->build($mail, null, null)->getTo()[0]->getAddress());
        Settings::set('mail_test_address', 'catchall@example.org');
        $email = app(Sender::class)->build($mail, null, null);
        $this->assertSame('catchall@example.org', $email->getTo()[0]->getAddress());
        $this->assertStringStartsWith('[TEST → ann@example.org]', (string) $email->getSubject());
        config(['mail.default' => 'array']);
        RuntimeSettings::apply();
        Mail::purge('array');
        Mail::raw('x', fn ($m) => $m->to('login@example.org')->subject('Login'));
        /** @var ArrayTransport $transport */
        $transport = Mail::mailer()->getSymfonyTransport();
        $recipients = $transport->messages()->last()?->getEnvelope()->getRecipients() ?? [];
        $this->assertSame(['catchall@example.org'], array_map(fn ($a) => $a->getAddress(), $recipients), 'login links too');
        $this->actingAs($this->admin())->withSession(['two_factor_passed' => true])->get('/admin')->assertSee('all mails go to catchall@example.org');
    }
}

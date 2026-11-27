<?php

namespace App\Filament\Resources\MailTemplates\Pages;

use App\Filament\Resources\MailTemplates\MailTemplateResource;
use App\Models\Event;
use App\Models\MailTemplate;
use App\Models\MailTemplateContent;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Language;
use App\Support\Mail\Composer;
use App\Support\Mail\MailException;
use App\Support\Mail\Placeholders;
use App\Support\Mail\PreviewFrame;
use App\Support\Mail\TemplateRenderer;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Preview of a template with the data of a real recipient, chosen from a list, for desktop and phone, and a test
 * mail to oneself (MT09, TP08).
 */
class PreviewMailTemplate extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MailTemplateResource::class;

    protected string $view = 'filament.pages.mail-template-preview';

    /** How many people the list offers before typing is needed. */
    private const PEOPLE = 100;

    public string $search = '';

    public ?int $person = null;

    public ?int $event = null;

    public string $width = 'desktop';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(MailTemplateResource::canViewAny(), 403);
    }

    public function getTitle(): string
    {
        return __('admin.mail.preview').': '.$this->template()->label();
    }

    /** Sends the preview to the person who is logged in, never to somebody else. */
    public function test(): void
    {
        abort_unless(Permissions::current('mailings', 'edit'), 403);
        /** @var User $user */
        $user = auth()->user();
        $address = $user->person?->primaryEmail() ?? $user->email;
        $rendered = $this->renderedMail();
        app(Composer::class)->queueRaw($address, $user->name, '[TEST] '.$rendered['subject'], $rendered['html'], Language::current());
        Audit::record('mail.test_sent', $this->template());
        Notification::make()->title(__('admin.mail.test_sent', ['to' => $address]))->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        try {
            $rendered = $this->renderedMail();
            $html = app(Composer::class)->layout($rendered['subject'], $rendered['html'], Language::current(), '#');
            $preview = ['subject' => $rendered['subject'], 'frame' => PreviewFrame::make($html, $rendered['subject'], 560), 'text' => $rendered['text'], 'error' => null];
        } catch (MailException $e) {
            $preview = ['subject' => '', 'frame' => new HtmlString(''), 'text' => '', 'error' => __('admin.mail.template_error', ['error' => $e->getMessage()])];
        }
        $search = trim($this->search);

        return [
            'preview' => $preview,
            'real' => Permissions::current('people'),
            // The list is there at once (first names in alphabetical order); typing narrows it down.
            'people' => ! Permissions::current('people') ? [] : Person::query()->whereNull('anonymised_at')
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('last_name', 'like', $search.'%')->orWhere('first_name', 'like', $search.'%')))
                ->when($this->person !== null, fn ($q) => $q->orWhere('id', $this->person))
                ->orderBy('last_name')->orderBy('first_name')->limit(self::PEOPLE)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->last_name.', '.$p->first_name])->all(),
            'morePeople' => Permissions::current('people') && Person::query()->whereNull('anonymised_at')->count() > self::PEOPLE,
            'chosen' => $this->person()?->fullName(),
            'events' => Event::query()->orderByDesc('starts_at')->limit(50)->pluck('title', 'id')->all(),
            'maySend' => Permissions::current('mailings', 'edit'),
        ];
    }

    /** @return array{subject: string, html: string, text: string} */
    private function renderedMail(): array
    {
        $content = $this->template()->contents()->first();
        if (! $content instanceof MailTemplateContent) {
            throw new MailException('No content.');
        }

        return app(TemplateRenderer::class)->renderStrings($content->subject, $content->html, $content->text, $this->context(Language::current()));
    }

    /**
     * Data of the chosen person; parts that are not chosen are example data.
     *
     * @return array<string, mixed>
     */
    private function context(string $locale): array
    {
        $sample = Placeholders::sample($this->template()->groupKey()); // only what mails of this group have
        $person = $this->person();
        $event = $this->event !== null ? Event::query()->find($this->event) : null;
        if ($person === null && $event === null) {
            return $sample;
        }
        $recipient = $person ?? new Person(['first_name' => 'Alex', 'last_name' => 'Example', 'locale' => $locale]);
        $children = $person !== null ? array_values($person->wards()->get()->all()) : [];
        $participation = $event === null ? null : Participation::query()->where('event_id', $event->id)->whereIn('person_id', [$person?->id, ...array_map(fn (Person $c) => $c->id, $children)])->first();
        $real = $person === null ? ['event' => null] : app(Placeholders::class)->for($recipient, $locale, ['event' => $event, 'participation' => $participation, 'children' => $children, 'household' => $person->households()->first(), 'login' => false]);
        if ($person === null) {
            $real = ['event' => app(Placeholders::class)->eventValues($event, $locale)];
        }

        // The links of other people are never shown or sent.
        return ['links' => $sample['links']] + array_filter($real, fn ($value) => $value !== null && $value !== []) + $sample;
    }

    private function person(): ?Person
    {
        return $this->person !== null && Permissions::current('people') ? Person::query()->whereNull('anonymised_at')->find($this->person) : null;
    }

    private function template(): MailTemplate
    {
        /** @var MailTemplate $template */
        $template = $this->getRecord();

        return $template;
    }
}

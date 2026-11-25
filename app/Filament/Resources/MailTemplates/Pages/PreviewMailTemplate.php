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
 * Preview of a template with the data of a real recipient, in all languages, for desktop and phone, and a test mail to
 * oneself (MT09, TP08).
 */
class PreviewMailTemplate extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MailTemplateResource::class;

    protected string $view = 'filament.pages.mail-template-preview';

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

    /** Sends the preview of one language to the person who is logged in, never to somebody else. */
    public function test(string $locale): void
    {
        abort_unless(Permissions::current('mailings', 'edit'), 403);
        /** @var User $user */
        $user = auth()->user();
        $address = $user->person?->primaryEmail() ?? $user->email;
        $rendered = $this->contentFor($locale);
        app(Composer::class)->queueRaw($address, $user->name, '[TEST] '.$rendered['subject'], $rendered['html'], $locale);
        Audit::record('mail.test_sent', $this->template(), null, ['locale' => $locale]);
        Notification::make()->title(__('admin.mail.test_sent', ['to' => $address]))->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $previews = [];
        foreach ($this->template()->contents()->orderBy('locale')->get() as $content) {
            try {
                $rendered = $this->contentFor($content->locale);
                $html = app(Composer::class)->layout($rendered['subject'], $rendered['html'], $content->locale, '#');
                $previews[$content->locale] = ['subject' => $rendered['subject'], 'frame' => PreviewFrame::make($html, strtoupper($content->locale), 560), 'text' => $rendered['text']];
            } catch (MailException $e) {
                $previews[$content->locale] = ['subject' => __('admin.mail.template_error', ['error' => $e->getMessage()]), 'frame' => new HtmlString(''), 'text' => ''];
            }
        }

        return [
            'previews' => $previews,
            'real' => Permissions::current('people'),
            'people' => Permissions::current('people') && mb_strlen(trim($this->search)) >= 2 ? Person::query()->whereNull('anonymised_at')
                ->where(fn ($q) => $q->where('last_name', 'like', trim($this->search).'%')->orWhere('first_name', 'like', trim($this->search).'%'))->orderBy('last_name')->limit(20)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->fullName()])->all() : [],
            'chosen' => $this->person()?->fullName(),
            'events' => Event::query()->orderByDesc('starts_at')->limit(50)->pluck('title', 'id')->all(),
            'maySend' => Permissions::current('mailings', 'edit'),
        ];
    }

    /** @return array{subject: string, html: string, text: string} */
    private function contentFor(string $locale): array
    {
        $content = $this->template()->contents()->where('locale', $locale)->first();
        if (! $content instanceof MailTemplateContent) {
            throw new MailException('No content.');
        }

        return app(TemplateRenderer::class)->renderStrings($content->subject, $content->html, $content->text, $this->context($locale));
    }

    /**
     * Data of the chosen person; parts that are not chosen are example data.
     *
     * @return array<string, mixed>
     */
    private function context(string $locale): array
    {
        $sample = Placeholders::sample();
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

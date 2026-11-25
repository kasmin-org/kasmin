<?php

namespace App\Filament\Resources\Mailings\Pages;

use App\Filament\Resources\Mailings\MailingResource;
use App\Models\Mailing;
use App\Models\OutgoingMail;
use App\Support\Auth\Permissions;
use App\Support\Mail\Mailings;
use App\Support\Mail\MailLinks;
use App\Support\Mail\PreviewFrame;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Review of a mailing: page through the finished mails, exclusions, progress, pause and cancel (MM05, MM08).
 */
class ReviewMailing extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MailingResource::class;

    protected string $view = 'filament.pages.mailing-review';

    public int $position = 0;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(MailingResource::canViewAny(), 403);
    }

    public function getTitle(): string
    {
        return __('admin.mail.review').': '.$this->mailing()->name;
    }

    public function go(int $step): void
    {
        $this->position = max(0, min($this->mailing()->mails()->count() - 1, $this->position + $step));
    }

    public function pause(): void
    {
        $this->authorizeChange('sending');
        Notification::make()->title(__('admin.mail.paused', ['count' => app(Mailings::class)->pause($this->mailing())]))->success()->send();
    }

    public function resume(): void
    {
        $this->authorizeChange('paused');
        Notification::make()->title(__('admin.mail.resumed', ['count' => app(Mailings::class)->resume($this->mailing())]))->success()->send();
    }

    public function cancel(): void
    {
        $this->authorizeChange('sending', 'paused', 'prepared', 'waiting');
        app(Mailings::class)->cancel($this->mailing());
        Notification::make()->title(__('admin.mail.mailing_status.cancelled'))->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $mailing = $this->mailing()->refresh();
        $counts = $mailing->mails()->toBase()->selectRaw('status, count(*) as number')->groupBy('status')->pluck('number', 'status')->map(fn ($n) => (int) $n)->all();
        $mail = $mailing->mails()->orderBy('id')->skip($this->position)->first();

        return [
            'mailing' => $mailing, 'counts' => $counts, 'total' => array_sum($counts),
            'done' => array_sum(array_intersect_key($counts, array_flip(['sent', 'failed', 'bounced', 'cancelled']))),
            'mail' => $mail, 'frame' => $mail instanceof OutgoingMail ? PreviewFrame::make(MailLinks::replace($mail->html, '#'), $mail->subject, 520) : null,
            'attachments' => $mail instanceof OutgoingMail ? array_values(array_filter(array_map(fn (array $a) => $a['name'] ?? (isset($a['document_id']) ? __('admin.event.document') : null), $mail->attachments ?? []))) : [],
            'excluded' => in_array($mailing->status, ['draft', 'prepared', 'waiting'], true) ? app(Mailings::class)->plan($mailing)['excluded'] : [],
            'mayEdit' => Permissions::current('mailings', 'edit'),
        ];
    }

    private function authorizeChange(string ...$statuses): void
    {
        abort_unless(Permissions::current('mailings', 'edit') && in_array($this->mailing()->refresh()->status, $statuses, true), 403);
    }

    private function mailing(): Mailing
    {
        /** @var Mailing $mailing */
        $mailing = $this->getRecord();

        return $mailing;
    }
}

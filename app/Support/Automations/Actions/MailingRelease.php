<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Mailing;
use App\Support\Automations\Action;
use App\Support\Mail\Mailings;

/**
 * MM07: large mailings are sent after an approval.
 */
class MailingRelease implements Action
{
    public function plan(Automation $automation, ?Event $event): array
    {
        return array_values(Mailing::query()->where('status', 'waiting')->withCount(['mails' => fn ($q) => $q->where('status', 'held')])->get()
            ->map(fn (Mailing $m) => ['key' => 'mailing:'.$m->id, 'label' => (string) __('automations.items.mailing', ['name' => $m->name, 'count' => (int) $m->getAttribute('mails_count')])])->all());
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $mailing = Mailing::query()->where('status', 'waiting')->find((int) substr($item['key'], 8));

        return $mailing === null ? 'skipped' : 'released: '.app(Mailings::class)->send($mailing);
    }
}

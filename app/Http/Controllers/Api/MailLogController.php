<?php

namespace App\Http\Controllers\Api;

use App\Models\ContactPoint;
use App\Models\MailTemplate;
use App\Models\MailTemplateVersion;
use App\Models\OutgoingMail;
use App\Models\SenderProfile;
use App\Support\Language;
use App\Support\Mail\BadAddresses;
use App\Support\Mail\MailLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Versions of mail templates, the mail log, addresses that do not work and sender profiles. Scopes: mail:read,
 * mail:write; changing sender profiles needs settings:write. Passwords and keys of mail accounts are never part
 * of an answer, and mails are returned as stored: without personal login or approval links.
 */
class MailLogController extends ApiController
{
    /** The versions of a template, newest first, each with its subject and text. */
    public function versions(string $template): JsonResponse
    {
        $this->scope('mail:read');
        $versions = $this->template($template)->versions()->with('changedBy')->get();

        // A version holds one text; those of the versions up to 0.27 hold one per language, of which the one in the
        // language of the installation is shown (it is the one a restore brings back).
        return response()->json(['data' => $versions->map(function (MailTemplateVersion $v): array {
            $contents = collect((array) ($v->snapshot['contents'] ?? []));
            $c = (array) ($contents->firstWhere('locale', Language::current()) ?? $contents->first() ?? []);

            return [
                'version' => $v->version, 'created_at' => $v->created_at->toIso8601String(), 'changed_by' => $v->changedBy?->name,
                'subject' => $c['subject'] ?? null, 'html' => $c['html'] ?? null, 'text' => $c['text'] ?? null,
            ];
        })->values()]);
    }

    /** Makes the contents of an earlier version the current ones; this is a new version, nothing is lost. */
    public function restoreVersion(string $template, int $version): JsonResponse
    {
        $this->scope('mail:write');
        $model = $this->template($template);
        $model->restoreVersion($model->versions()->where('version', $version)->firstOrFail());
        $model->refresh();

        return response()->json(['data' => [
            'key' => $model->key, 'name' => $model->name, 'version' => $model->version, 'restored' => $version,
            'subject' => $model->contents()->value('subject'), 'html' => $model->contents()->value('html'), 'text' => $model->contents()->value('text'),
        ]]);
    }

    /**
     * The mail log, newest first; filter by status (held, queued, paused, sending, sent, failed, bounced,
     * cancelled) and person. In pages. The text is the stored one: a personal login or approval link is only made
     * when a mail is sent and is never part of this answer.
     */
    public function outgoing(Request $request): JsonResponse
    {
        $this->scope('mail:read');
        $data = $request->validate(['status' => ['sometimes', 'nullable', Rule::in(OutgoingMail::STATUSES)], 'person' => ['sometimes', 'nullable', 'string']]);
        $person = isset($data['person']) ? $this->person($data['person']) : null;
        $page = OutgoingMail::query()->with('person')
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($person !== null, fn ($q) => $q->where('person_id', $person?->id))
            ->orderByDesc('id')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (OutgoingMail $m) => [
            'id' => $m->id, 'to' => $m->to_address, 'to_name' => $m->to_name, 'person' => $m->person?->public_id, 'subject' => $m->subject, 'text' => self::withoutPersonalLinks($m->text),
            'kind' => $m->kind, 'status' => $m->status, 'mailing' => $m->mailing_id, 'created_at' => $m->getAttribute('created_at')?->toIso8601String(),
            'sent_at' => $m->sent_at?->toIso8601String(), 'error' => $m->getAttribute('last_error'),
        ])->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** E-mail addresses that came back as not existing, newest first. */
    public function badAddresses(): JsonResponse
    {
        $this->scope('mail:read');
        $points = ContactPoint::query()->where('type', 'email')->whereNotNull('bounced_at')->with('person')->orderByDesc('bounced_at')->limit(500)->get();

        return response()->json(['data' => $points->map(fn (ContactPoint $c) => $this->presentBad($c))->values()]);
    }

    /** The address works again: mails are sent to it as before. */
    public function clearBadAddress(int $address): JsonResponse
    {
        $this->scope('mail:write');
        $point = ContactPoint::query()->where('type', 'email')->whereNotNull('bounced_at')->with('person')->findOrFail($address);
        app(BadAddresses::class)->clear($point);

        return response()->json(['data' => $this->presentBad($point->refresh())]);
    }

    /** The sender of the installation: from name and address, reply-to and the mail account (name only). There is one. */
    public function senderProfiles(): JsonResponse
    {
        $this->scope('mail:read');

        $sender = SenderProfile::main();

        return response()->json(['data' => $sender === null ? [] : [$this->presentProfile($sender->load('account'))]]);
    }

    /** Creates the sender for the mail account (mail_account = its number), if there is none yet – an installation has one sender. Needs settings:write. */
    public function createSenderProfile(Request $request): JsonResponse
    {
        $this->scope('settings:write');
        $data = $this->profileData($request, true);
        if (SenderProfile::query()->exists()) {
            throw ValidationException::withMessages(['from_address' => [__('admin.mail.one_sender')]]);
        }
        $profile = SenderProfile::query()->create($data);

        return response()->json(['data' => $this->presentProfile($profile->load('account'))], 201);
    }

    /** Changes the sender. Needs settings:write. */
    public function updateSenderProfile(Request $request, int $profile): JsonResponse
    {
        $this->scope('settings:write');
        $model = SenderProfile::query()->findOrFail($profile);
        $model->update($this->profileData($request, false));

        return response()->json(['data' => $this->presentProfile($model->refresh()->load('account'))]);
    }

    /**
     * Stored mails only hold a placeholder instead of a personal link (MailLinks). Should a link with a real
     * token ever be stored, it is replaced by the placeholder here as well.
     */
    public static function withoutPersonalLinks(string $text): string
    {
        return (string) preg_replace('#/(login/link|approvals)/(?!'.preg_quote(MailLinks::PLACEHOLDER, '#').'(?![A-Za-z0-9_-]))[^\s"\'<>)\]]+#', '/$1/'.MailLinks::PLACEHOLDER, $text);
    }

    private function template(string $key): MailTemplate
    {
        return MailTemplate::query()->where('key', $key)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function profileData(Request $request, bool $new): array
    {
        $need = $new ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$need, 'string', 'max:200'], 'from_name' => [$need, 'string', 'max:200'], 'from_address' => [$need, 'email', 'max:254'],
            'reply_to' => ['sometimes', 'nullable', 'email', 'max:254'], 'mail_account' => [$need, 'integer', 'exists:mail_accounts,id'],
        ]);
        if (array_key_exists('mail_account', $data)) {
            $data['mail_account_id'] = (int) $data['mail_account'];
            unset($data['mail_account']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function presentProfile(SenderProfile $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'from_name' => $p->from_name, 'from_address' => $p->from_address, 'reply_to' => $p->reply_to,
            'mail_account' => $p->mail_account_id, 'mail_account_name' => $p->account?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function presentBad(ContactPoint $c): array
    {
        return [
            'id' => (int) $c->getKey(), 'address' => $c->value, 'person' => $c->person?->public_id, 'name' => $c->person?->fullName(),
            'bounced_at' => $c->bounced_at?->toIso8601String(), 'reason' => $c->getAttribute('bounce_reason'),
        ];
    }
}

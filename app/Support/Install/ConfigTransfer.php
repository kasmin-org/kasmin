<?php

namespace App\Support\Install;

use App\Models\Automation;
use App\Models\MailTemplate;
use App\Support\Audit\Audit;
use App\Support\Automations\Engine;
use App\Support\Mail\TemplateRenderer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Export and import of mail templates and automations as JSON (AP03), e.g. to share them between organisations or
 * keep them in version control. Import creates what is missing; existing entries are only replaced with $update
 * (templates get a new version). Templates are validated with the sandbox before anything is saved; automations
 * are always imported switched off.
 */
class ConfigTransfer
{
    public const FORMAT = 'kasmin-config/1';

    /** @return array<string, mixed> */
    public function export(bool $templates = true, bool $automations = true): array
    {
        $data = ['format' => self::FORMAT, 'kasmin' => config('kasmin.version'), 'exported_at' => now()->toIso8601String()];
        if ($templates) {
            $data['mail_templates'] = MailTemplate::query()->with('contents')->orderBy('key')->get()->map(fn (MailTemplate $t) => [
                'key' => $t->key, 'category' => $t->category, 'audience' => $t->audience, 'name' => $t->name,
                'contents' => $t->contents->map(fn ($c) => ['locale' => $c->locale, 'subject' => $c->subject, 'html' => $c->html, 'text' => $c->text])->values()->all(),
            ])->values()->all();
        }
        if ($automations) {
            $data['automations'] = Automation::query()->orderBy('key')->get()->map(fn (Automation $a) => [
                'key' => $a->key, 'name' => $a->name, 'description' => $a->description, 'action' => $a->action, 'config' => $a->config,
                'trigger' => $a->trigger, 'trigger_config' => $a->trigger_config, 'mode' => $a->mode, 'approver_roles' => $a->approver_roles, 'max_items' => $a->max_items,
            ])->values()->all();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{created: list<string>, updated: list<string>, skipped: list<string>}
     */
    public function import(array $data, bool $update = false, bool $dryRun = false): array
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Unknown file format (expected '.self::FORMAT.').');
        }
        $result = ['created' => [], 'updated' => [], 'skipped' => []];
        $renderer = app(TemplateRenderer::class);
        foreach ((array) ($data['mail_templates'] ?? []) as $t) {
            $key = (string) ($t['key'] ?? '');
            if ($key === '' || ! in_array($t['category'] ?? '', MailTemplate::CATEGORIES, true)) {
                throw new RuntimeException("Template {$key}: missing key or unknown category.");
            }
            foreach ((array) ($t['contents'] ?? []) as $c) {
                if (($errors = $renderer->validate((string) ($c['subject'] ?? ''), (string) ($c['html'] ?? ''), $c['text'] ?? null)) !== []) {
                    throw new RuntimeException("Template {$key} ({$c['locale']}): ".implode('; ', $errors));
                }
            }
        }
        foreach ((array) ($data['automations'] ?? []) as $a) {
            if (! isset(Engine::ACTIONS[$a['action'] ?? '']) || ! in_array($a['trigger'] ?? '', Automation::TRIGGERS, true) || ! in_array($a['mode'] ?? '', Automation::MODES, true)) {
                throw new RuntimeException('Automation '.($a['key'] ?? '?').': unknown action, trigger or mode.');
            }
        }
        if ($dryRun) {
            foreach ((array) ($data['mail_templates'] ?? []) as $t) {
                $exists = MailTemplate::query()->where('key', $t['key'])->exists();
                $result[$exists ? ($update ? 'updated' : 'skipped') : 'created'][] = 'template:'.$t['key'];
            }
            foreach ((array) ($data['automations'] ?? []) as $a) {
                $exists = Automation::query()->where('key', $a['key'])->exists();
                $result[$exists ? ($update ? 'updated' : 'skipped') : 'created'][] = 'automation:'.$a['key'];
            }

            return $result;
        }

        DB::transaction(function () use ($data, $update, &$result) {
            foreach ((array) ($data['mail_templates'] ?? []) as $t) {
                $template = MailTemplate::query()->where('key', $t['key'])->first();
                if ($template !== null && ! $update) {
                    $result['skipped'][] = 'template:'.$t['key'];

                    continue;
                }
                $isNew = $template === null;
                $template ??= MailTemplate::query()->create(['key' => $t['key'], 'category' => $t['category'], 'audience' => $t['audience'] ?? 'guardians', 'name' => $t['name'], 'is_standard' => false]);
                $template->fill(['category' => $t['category'], 'audience' => $t['audience'] ?? $template->audience, 'name' => $t['name']])->save();
                foreach ((array) $t['contents'] as $c) {
                    $template->contents()->updateOrCreate(['locale' => $c['locale']], ['subject' => $c['subject'], 'html' => $c['html'], 'text' => $c['text'] ?? null]);
                }
                if (! $isNew) {
                    $template->increment('version');
                    $template->versions()->create([
                        'version' => $template->version, 'changed_by_id' => auth()->id(),
                        'snapshot' => ['name' => $template->name, 'contents' => $template->contents()->get(['locale', 'subject', 'html', 'text'])->toArray()],
                    ]);
                }
                $result[$isNew ? 'created' : 'updated'][] = 'template:'.$t['key'];
            }
            foreach ((array) ($data['automations'] ?? []) as $a) {
                $automation = Automation::query()->where('key', $a['key'])->first();
                if ($automation !== null && ! $update) {
                    $result['skipped'][] = 'automation:'.$a['key'];

                    continue;
                }
                $values = ['name' => $a['name'], 'description' => $a['description'] ?? null, 'action' => $a['action'], 'config' => $a['config'] ?? null,
                    'trigger' => $a['trigger'], 'trigger_config' => $a['trigger_config'] ?? null, 'mode' => $a['mode'], 'approver_roles' => $a['approver_roles'] ?? null,
                    'max_items' => (int) ($a['max_items'] ?? 200)];
                if ($automation === null) {
                    Automation::query()->create(['key' => $a['key'], 'active' => false, 'is_standard' => false] + $values);
                    $result['created'][] = 'automation:'.$a['key'];
                } else {
                    $automation->fill($values)->save(); // keeps its active state
                    $result['updated'][] = 'automation:'.$a['key'];
                }
            }
        });
        Audit::record('config.imported', null, null, ['created' => count($result['created']), 'updated' => count($result['updated']), 'skipped' => count($result['skipped'])]);

        return $result;
    }
}

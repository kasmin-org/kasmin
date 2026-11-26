<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * An installation works in one language. Names, labels and texts that administrators enter are stored once, no longer
 * once per language, and a mail template has one text.
 *
 * Of the two texts stored so far, the one in the language of the installation stays (the other one if that is empty).
 * For mail templates the text of the other language is kept in the version history first, so nothing is lost.
 */
return new class extends Migration
{
    /** table => columns that held {"de": …, "en": …} */
    private const TEXTS = [
        'roles' => ['name'], 'automations' => ['name', 'description'], 'forms' => ['name', 'description'],
        'form_fields' => ['label', 'help'], 'public_forms' => ['title', 'intro'], 'mail_templates' => ['name'],
        'membership_types' => ['name'], 'event_installments' => ['label'], 'events' => ['cancellation_text'],
    ];

    public function up(): void
    {
        $language = DB::table('system_meta')->where('key', 'default_locale')->value('value');
        $language = in_array($language, ['de', 'en'], true) ? $language : (string) config('app.locale');

        foreach (self::TEXTS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->select(['id', ...$columns])->orderBy('id')->cursor() as $row) {
                $changes = [];
                foreach ($columns as $column) {
                    $texts = is_string($row->{$column}) ? json_decode($row->{$column}, true) : null;
                    if (is_array($texts)) {
                        $text = self::pick($texts, $language);
                        $changes[$column] = $text === null ? null : json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
                if ($changes !== []) {
                    DB::table($table)->where('id', $row->id)->update($changes);
                }
            }
        }

        // Choices and columns of form fields: value => text.
        foreach (DB::table('form_fields')->whereNotNull('options')->select(['id', 'options'])->orderBy('id')->cursor() as $row) {
            $options = json_decode((string) $row->options, true);
            if (! is_array($options)) {
                continue;
            }
            $changed = false;
            foreach (['choices', 'fields'] as $part) {
                foreach ((array) ($options[$part] ?? []) as $value => $texts) {
                    if (is_array($texts)) {
                        $options[$part][$value] = (string) self::pick($texts, $language);
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                DB::table('form_fields')->where('id', $row->id)->update(['options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
        }

        // Mail templates: one text. The others go to the version history before they are removed.
        foreach (DB::table('mail_templates')->orderBy('id')->get(['id', 'name', 'version']) as $template) {
            $contents = DB::table('mail_template_contents')->where('mail_template_id', $template->id)->orderBy('id')->get();
            if ($contents->count() < 2) {
                continue;
            }
            $kept = $contents->firstWhere('locale', $language) ?? $contents->first();
            if ($kept === null) {
                continue;
            }
            if (! DB::table('mail_template_versions')->where('mail_template_id', $template->id)->where('version', $template->version)->exists()) {
                DB::table('mail_template_versions')->insert([
                    'mail_template_id' => $template->id, 'version' => $template->version, 'changed_by_id' => null, 'created_at' => now(),
                    'snapshot' => json_encode(['name' => json_decode((string) $template->name, true), 'contents' => $contents->map(fn ($c) => ['locale' => $c->locale, 'subject' => $c->subject, 'html' => $c->html, 'text' => $c->text])->all()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
            DB::table('mail_template_contents')->where('mail_template_id', $template->id)->where('id', '!=', $kept->id)->delete();
        }
    }

    /** @param  array<array-key, mixed>  $texts */
    private static function pick(array $texts, string $language): ?string
    {
        foreach ([$texts[$language] ?? null, $texts['en'] ?? null, $texts['de'] ?? null, reset($texts)] as $text) {
            if (is_scalar($text) && trim((string) $text) !== '') {
                return (string) $text;
            }
        }

        return null;
    }
};

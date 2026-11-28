<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * 0.31.0 made the sign-up form in English when the language of a German installation was only in its configuration
 * (APP_LOCALE), not in the settings. If the form is still exactly as it was made – switched off, never changed – it
 * gets its German title and address.
 */
return new class extends Migration
{
    public function up(): void
    {
        $locale = DB::table('system_meta')->where('key', 'default_locale')->value('value');
        if (! str_contains(is_string($locale) && $locale !== '' ? $locale : (string) config('app.locale'), 'de')) {
            return;
        }
        $form = DB::table('public_forms')->where('kind', 'signup')->get()->first(fn (object $form) => ! (bool) $form->active
            && json_decode((string) $form->title) === 'Create a family account' && str_starts_with((string) $form->slug, 'sign-up')
            && (string) $form->updated_at === (string) $form->created_at);
        if ($form === null) {
            return;
        }
        $slug = 'registrierung';
        for ($n = 2; DB::table('public_forms')->where('slug', $slug)->where('id', '!=', $form->id)->exists(); $n++) {
            $slug = 'registrierung-'.$n;
        }
        DB::table('public_forms')->where('id', $form->id)->update(['slug' => $slug, 'title' => json_encode('Familienkonto anlegen', JSON_UNESCAPED_UNICODE)]);
    }
};

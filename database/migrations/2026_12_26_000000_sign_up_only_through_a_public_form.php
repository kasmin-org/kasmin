<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Signing up for a family account is a kind of public form (B16) instead of a page that was always open. Every
 * installation gets such a form, switched off – also existing ones, so administrators switch it on on purpose. Forms
 * can be shown on the start page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('public_forms', 'on_start_page')) {
            Schema::table('public_forms', function (Blueprint $table) {
                $table->boolean('on_start_page')->default(false);
            });
        }
        if (DB::table('public_forms')->where('kind', 'signup')->exists()) {
            return;
        }
        // The language of the installation: the setting, otherwise the configuration (as Language::current()).
        $locale = DB::table('system_meta')->where('key', 'default_locale')->value('value');
        $de = str_contains(is_string($locale) && $locale !== '' ? $locale : (string) config('app.locale'), 'de');
        $slug = $de ? 'registrierung' : 'sign-up';
        for ($n = 2; DB::table('public_forms')->where('slug', $slug)->exists(); $n++) {
            $slug = ($de ? 'registrierung-' : 'sign-up-').$n;
        }
        $title = $de ? 'Familienkonto anlegen' : 'Create a family account';
        DB::table('public_forms')->insert([
            'slug' => $slug, 'kind' => 'signup', 'title' => json_encode($title, JSON_UNESCAPED_UNICODE), 'active' => false,
            'needs_approval' => false, 'ask_newsletter' => true, 'on_start_page' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Existing installations: until now everybody could sign up. Tell the administrators after the update.
        if (DB::table('users')->exists() && Schema::hasTable('system_notifications')) {
            DB::table('system_notifications')->insertOrIgnore([
                'key' => 'signup_form_off', 'level' => 'warning', 'url' => '/admin/public-forms',
                'title' => json_encode(['de' => 'Registrierung ist ausgeschaltet', 'en' => 'Sign-up is switched off'], JSON_UNESCAPED_UNICODE),
                'message' => json_encode([
                    'de' => 'Neue Familien registrieren sich jetzt über ein öffentliches Formular der Art „Registrierung (Familienkonto)“. Es wurde angelegt und ist ausgeschaltet – bis Sie es einschalten, kann sich niemand selbst registrieren.',
                    'en' => 'New families now sign up through a public form of the kind “Sign-up (family account)”. It was created and is switched off – until you switch it on, nobody can sign up.',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
};

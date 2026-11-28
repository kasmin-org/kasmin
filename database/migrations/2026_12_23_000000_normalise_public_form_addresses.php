<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Addresses of public forms are saved normalised (B15): lower case, no umlauts, only a–z, 0–9 and hyphens. An address
 * that changes keeps forwarding, so links on websites still work.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('public_forms', 'old_slugs')) {
            Schema::table('public_forms', function (Blueprint $table) {
                $table->json('old_slugs')->nullable();
            });
        }
        $taken = DB::table('public_forms')->pluck('slug')->all();
        foreach (DB::table('public_forms')->orderBy('id')->get(['id', 'slug']) as $form) {
            $slug = trim(substr(Str::slug(trim((string) $form->slug), '-', 'de'), 0, 64), '-') ?: 'form-'.$form->id;
            if ($slug === $form->slug) {
                continue;
            }
            $base = $slug;
            for ($n = 2; in_array($slug, $taken, true); $n++) {
                $slug = substr($base, 0, 60).'-'.$n;
            }
            $taken[] = $slug;
            DB::table('public_forms')->where('id', $form->id)->update(['slug' => $slug, 'old_slugs' => json_encode([$form->slug])]);
        }
    }
};

<?php

namespace App\Support\Forms;

/**
 * Ready-made choice lists, e.g. the health insurance carriers of a country (SF12). Names of carriers are the same in
 * every language. Further lists can be added with ChoiceLists::register() in a service provider.
 */
class ChoiceLists
{
    private const LISTS = [
        'carriers_at' => ['oegk' => 'ÖGK', 'bvaeb' => 'BVAEB', 'svs' => 'SVS', 'kfa_wien' => 'KFA Wien', 'kfl_ooe' => 'KFL Oberösterreich', 'other_kfa' => 'KFA (Gemeinde, Land)'],
        'carriers_de' => ['tk' => 'Techniker Krankenkasse', 'barmer' => 'BARMER', 'dak' => 'DAK-Gesundheit', 'aok' => 'AOK', 'ikk' => 'IKK', 'bkk' => 'BKK', 'kkh' => 'KKH', 'hek' => 'HEK', 'hkk' => 'hkk', 'knappschaft' => 'Knappschaft', 'private' => 'Private Krankenversicherung'],
        'carriers_ch' => ['css' => 'CSS', 'helsana' => 'Helsana', 'swica' => 'SWICA', 'concordia' => 'CONCORDIA', 'visana' => 'Visana', 'sanitas' => 'Sanitas', 'kpt' => 'KPT', 'groupe_mutuel' => 'Groupe Mutuel', 'assura' => 'Assura', 'sympany' => 'Sympany', 'atupri' => 'Atupri', 'oekk' => 'ÖKK'],
    ];

    /** @var array<string, array<string, string>> */
    private static array $custom = [];

    /** @param  array<string, string>  $choices */
    public static function register(string $key, array $choices): void
    {
        self::$custom[$key] = $choices;
    }

    /** @return array<string, string> */
    public static function get(string $key): array
    {
        return self::$custom[$key] ?? self::LISTS[$key] ?? [];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect([...array_keys(self::LISTS), ...array_keys(self::$custom)])->mapWithKeys(fn (string $key) => [$key => trans()->has('forms.choice_lists.'.$key) ? (string) __('forms.choice_lists.'.$key) : $key])->all();
    }
}

<?php

namespace Tests\Feature\Replay;

use Illuminate\Support\Carbon;

/**
 * Data for the replay of two camp years: either a file given by KASMIN_REPLAY_DATA (an anonymised data set of a real
 * organisation, never part of the repository), or a small fictitious data set of the same shape.
 *
 * Shape: families (guardian, children), adults (team), and per season the registrations with the dates and amounts
 * of the deposit and the balance invoice, when they were paid, and which ones were written off (= the child left).
 */
class ReplayData
{
    /** @return array<string, mixed> */
    public static function load(): array
    {
        $path = self::option('KASMIN_REPLAY_DATA');
        if ($path !== '' && is_readable($path)) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

            return $data;
        }

        return self::synthetic();
    }

    /** A setting of the run from the environment (data file, mail server, last day). */
    public static function option(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Twelve families over three seasons with everything the real years had: siblings, late registrations, children
     * who leave before paying, after the deposit and after the balance, a family that never pays, team, a weekend.
     *
     * @return array<string, mixed>
     */
    public static function synthetic(): array
    {
        $names = ['Ash', 'Birch', 'Cedar', 'Dogwood', 'Elm', 'Fir', 'Gum', 'Hazel', 'Ivy', 'Juniper', 'Kapok', 'Larch'];
        $first = ['Alder', 'Bay', 'Cy', 'Dale', 'Eden', 'Finn', 'Gale', 'Hope', 'Ira', 'Jo', 'Kit', 'Lee', 'Max', 'Nell', 'Oak', 'Pip', 'Quin', 'Rae'];
        $families = [];
        $children = [];
        foreach ($names as $i => $name) {
            $family = ['id' => 'F'.($i + 1), 'name' => $name, 'street' => ($i + 1).' Example Road', 'postal_code' => '1010', 'city' => 'Wien', 'country' => 'AT',
                'guardian' => ['first_name' => 'Kim', 'last_name' => $name, 'email' => strtolower($name).'@example.org', 'phone' => '+43 660 100000'.$i], 'children' => []];
            foreach (range(1, $i < 3 ? 2 : 1) as $n) {
                $child = ['id' => 'P'.(count($children) + 1), 'kind' => 'child', 'team' => false, 'family' => $family['id'], 'first_name' => $first[count($children)], 'last_name' => $name,
                    'gender' => count($children) % 2 === 0 ? 'female' : 'male', 'birth_date' => (2013 + (count($children) % 4)).'-0'.(1 + count($children) % 9).'-15'];
                $family['children'][] = $child;
                $children[] = $child;
            }
            $families[] = $family;
        }
        $adults = [];
        foreach (['Noor', 'Odell', 'Perry', 'Robin', 'Sage', 'Toby'] as $i => $name) {
            $adults[] = ['id' => 'T'.($i + 1), 'kind' => 'adult', 'team' => true, 'first_name' => $name, 'last_name' => 'Team', 'gender' => $i % 2 === 0 ? 'female' : 'male',
                'birth_date' => (1990 + $i).'-03-10', 'email' => strtolower($name).'.team@example.org', 'phone' => '+43 664 200000'.$i];
        }
        $paid = fn (string $issued, string $due, int $amount, int $after = 5) => ['issued_on' => $issued, 'due_on' => $due, 'amount' => $amount, 'state' => 'paid', 'paid_on' => Carbon::parse($issued)->addDays($after)->toDateString()];
        $off = fn (string $issued, string $due, int $amount) => ['issued_on' => $issued, 'due_on' => $due, 'amount' => $amount, 'state' => 'writeoff', 'paid_on' => null];
        $open = fn (string $issued, string $due, int $amount) => ['issued_on' => $issued, 'due_on' => $due, 'amount' => $amount, 'state' => 'open', 'paid_on' => null];
        $ids = array_column($children, 'id');

        // 2025: everybody but the last two families; one child leaves before paying, one after the balance invoice, one joins late.
        $s2025 = [];
        foreach (array_slice($ids, 0, 12) as $n => $id) {
            $s2025[] = ['person' => $id, 'deposit' => $n === 5 ? $off('2024-10-01', '2024-10-31', 80) : $paid('2024-10-01', '2024-10-31', 80, 3 + $n),
                ...($n === 5 ? [] : ['balance' => $n === 7 ? $off('2025-06-17', '2025-07-17', 310) : $paid('2025-06-17', '2025-07-17', 310, 2 + $n)])];
        }
        $s2025[] = ['person' => $ids[12], 'both' => $paid('2025-06-30', '2025-07-14', 390, 4)];
        // 2026: the same children without the two who left, two new ones; a family never pays the deposit, one pays after two reminders.
        $s2026 = [];
        foreach ([...array_slice($ids, 0, 5), $ids[6], ...array_slice($ids, 8, 7)] as $n => $id) {
            $deposit = match ($n) {
                3 => $off('2025-10-25', '2025-11-08', 80),
                4 => $paid('2025-10-25', '2025-11-08', 80, 45),
                default => $paid('2025-10-25', '2025-11-08', 80, 2 + $n),
            };
            $s2026[] = ['person' => $id, 'deposit' => $deposit, ...($n === 3 ? [] : ['balance' => $paid('2026-06-02', '2026-07-03', 340, 3 + $n)])];
        }
        $weekend = [];
        foreach (array_slice($adults, 0, 4) as $n => $adult) {
            $weekend[] = ['person' => $adult['id'], ...($n === 3 ? $off('2025-11-19', '2025-12-03', 95) : $paid('2025-11-19', '2025-12-03', 95, 2 + $n))];
        }
        // 2027: season started; half of the deposits are paid when the replay ends.
        $s2027 = [];
        foreach ([...array_slice($ids, 0, 3), $ids[4], $ids[6], ...array_slice($ids, 8, 6)] as $n => $id) {
            $s2027[] = ['person' => $id, 'deposit' => $n % 2 === 0 ? $paid('2026-11-15', '2026-12-01', 80, 2 + $n % 5) : $open('2026-11-15', '2026-12-01', 80)];
        }

        return ['note' => 'Fictitious data.', 'domain' => 'example.org', 'families' => $families, 'adults' => $adults,
            'seasons' => ['2025' => ['registrations' => $s2025, 'weekend' => []], '2026' => ['registrations' => $s2026, 'weekend' => $weekend], '2027' => ['registrations' => $s2027, 'weekend' => []]]];
    }
}

<?php

namespace App\Support\Import;

use Illuminate\Support\Str;

/**
 * Family builder (MI04): groups flat member rows into families by shared e-mail address, phone number, legal
 * guardian names (with the same last name or address) or address. Strong links (e-mail, phone, guardian names) form
 * the families used by the import; address-only links are reported as suggestions with lower confidence for review.
 */
class FamilyBuilder
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{key: string, rows: list<int>, names: list<string>, confidence: string, reasons: list<string>}>
     */
    public function suggest(array $rows): array
    {
        [$strong, $reasonsStrong] = $this->cluster($rows, false);
        [$allParents, $reasonsAll] = $this->cluster($rows, true);
        $all = [];
        foreach (array_keys($rows) as $i) {
            $all[$this->find($allParents, $i)][] = $i;
        }
        $strongGroups = [];
        foreach (array_keys($rows) as $i) {
            $strongGroups[$this->find($strong, $i)][] = $i;
        }
        $result = [];
        // Families found by e-mail, phone or legal guardian names: used by the import.
        foreach ($strongGroups as $root => $members) {
            if (count($members) > 1) {
                $result[] = $this->suggestion($rows, 'family-'.($root + 1), $members, 'high', $reasonsStrong[$root] ?? []);
            }
        }
        // Families that only share the address with others: a proposal to merge, for review.
        foreach ($all as $root => $members) {
            $roots = array_unique(array_map(fn (int $i) => $this->find($strong, $i), $members));
            if (count($roots) > 1) {
                $result[] = $this->suggestion($rows, 'address-'.($root + 1), $members, 'medium', $reasonsAll[$root] ?? ['same address']);
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $members
     * @param  list<string>  $reasons
     * @return array{key: string, rows: list<int>, names: list<string>, confidence: string, reasons: list<string>}
     */
    private function suggestion(array $rows, string $key, array $members, string $confidence, array $reasons): array
    {
        return [
            'key' => $key,
            'rows' => array_map(fn (int $i) => $i + 1, $members),
            'names' => array_map(fn (int $i) => trim(($rows[$i]['first_name'] ?? '').' '.($rows[$i]['last_name'] ?? '')), $members),
            'confidence' => $confidence,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * Family key per row index for the import: rows linked by strong evidence share a key.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, string|null>
     */
    public function familyKeys(array $rows): array
    {
        [$parents] = $this->cluster($rows, false);
        $sizes = [];
        foreach (array_keys($rows) as $i) {
            $root = $this->find($parents, $i);
            $sizes[$root] = ($sizes[$root] ?? 0) + 1;
        }
        $keys = [];
        foreach (array_keys($rows) as $i) {
            $root = $this->find($parents, $i);
            $hasGuardian = ! empty($rows[$i]['guardian1_first_name']) || ! empty($rows[$i]['guardian1_name']) || ! empty($rows[$i]['guardian1_email']);
            $keys[$i] = ($sizes[$root] > 1 || $hasGuardian) ? 'auto-'.$root : null;
        }

        return $keys;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: array<int, int>, 1: array<int, list<string>>} parent index per row (union-find) and link reasons per root
     */
    private function cluster(array $rows, bool $withAddress): array
    {
        $parents = array_keys($rows);
        $seen = [];
        $reasons = [];
        foreach ($rows as $i => $row) {
            foreach ($this->links($row, $withAddress) as $reason => $value) {
                $key = $reason.'|'.$value;
                if (isset($seen[$key])) {
                    $a = $this->find($parents, $i);
                    $b = $this->find($parents, $seen[$key]);
                    if ($a !== $b) {
                        $parents[max($a, $b)] = min($a, $b);
                    }
                    $reasons[$key] = $reason;
                } else {
                    $seen[$key] = $i;
                }
            }
        }
        $byRoot = [];
        foreach ($seen as $key => $i) {
            if (isset($reasons[$key])) {
                $byRoot[$this->find($parents, $i)][] = $reasons[$key];
            }
        }

        return [$parents, $byRoot];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function links(array $row, bool $withAddress): array
    {
        $links = [];
        foreach (['email', 'guardian1_email', 'guardian2_email'] as $n => $field) {
            if (! empty($row[$field])) {
                $links['same e-mail'.($n > 0 ? ' '.$n : '')] = Str::lower(trim((string) $row[$field]));
            }
        }
        foreach (['phone', 'guardian1_phone', 'guardian2_phone'] as $n => $field) {
            $digits = preg_replace('/\D+/', '', (string) ($row[$field] ?? ''));
            if (is_string($digits) && strlen($digits) >= 6) {
                $links['same phone'.($n > 0 ? ' '.$n : '')] = substr($digits, -9);
            }
        }
        // Guardian names count only together with the child's last name (common first names otherwise collide).
        $guardians = array_filter([$this->name($row, 1), $this->name($row, 2)]);
        sort($guardians);
        if ($guardians !== []) {
            $links['same legal guardians'] = implode('+', $guardians).'|'.Str::lower((string) ($row['last_name'] ?? ''));
        }
        if ($withAddress && ! empty($row['street']) && ! empty($row['postal_code'])) {
            $links['same address'] = Str::lower(preg_replace('/\s+/', '', (string) $row['street']).'|'.$row['postal_code']);
        }

        return $links;
    }

    /** @param  array<string, mixed>  $row */
    private function name(array $row, int $n): ?string
    {
        $first = trim((string) ($row["guardian{$n}_first_name"] ?? $row["guardian{$n}_name"] ?? ''));

        return $first !== '' ? Str::lower($first.' '.trim((string) ($row["guardian{$n}_last_name"] ?? $row['last_name'] ?? ''))) : null;
    }

    /** @param  array<int, int>  $parents */
    private function find(array &$parents, int $i): int
    {
        while ($parents[$i] !== $i) {
            $parents[$i] = $parents[$parents[$i]];
            $i = $parents[$i];
        }

        return $i;
    }
}

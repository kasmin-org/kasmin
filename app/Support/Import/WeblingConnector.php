<?php

namespace App\Support\Import;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Webling connector (MW01): reads members and their member groups through the Webling API (API key with read
 * permission) and turns them into import rows for PeopleImporter (source "webling", source_id = Webling member id).
 * The assignment of Webling properties to Kasmin fields is configurable, because Webling fields differ per
 * organisation; the defaults match Webling's standard member fields.
 */
class WeblingConnector
{
    /** Kasmin field => Webling member property (standard fields of a German Webling account). */
    public const DEFAULT_MAPPING = [
        'first_name' => 'Vorname', 'last_name' => 'Name', 'birth_date' => 'Geburtstag', 'gender' => 'Geschlecht',
        'email' => 'E-Mail', 'phone' => 'Mobile', 'street' => 'Strasse', 'postal_code' => 'PLZ', 'city' => 'Ort',
    ];

    /**
     * @param  array<string, string>  $mapping  Kasmin field => Webling property; empty = defaults
     * @param  array<string, array<string, string>>  $valueMap  e.g. ['gender' => ['m' => 'male', 'w' => 'female']]
     * @return list<array<string, mixed>>
     */
    public function rows(string $baseUrl, string $apiKey, array $mapping = [], array $valueMap = [], int $limit = 5000): array
    {
        $base = $this->base($baseUrl);
        $mapping = $mapping ?: self::DEFAULT_MAPPING;
        $ids = $this->ids($base, $apiKey, $limit);
        $members = $this->objects($base, $apiKey, 'member', $ids);
        /** @var list<int|string> $groupIds */
        $groupIds = array_values(array_unique(array_filter(array_merge(...array_map(fn (array $m) => (array) ($m['parents'] ?? []), $members ?: [[]])), fn ($id) => is_int($id) || is_string($id))));
        $groups = [];
        foreach ($this->objects($base, $apiKey, 'membergroup', $groupIds) as $group) {
            $groups[(int) $group['id']] = (string) ($group['properties']['title'] ?? '');
        }
        $rows = [];
        foreach ($members as $member) {
            $props = (array) ($member['properties'] ?? []);
            $row = ['source_id' => (string) ($member['id'] ?? '')];
            foreach ($mapping as $field => $property) {
                $value = $props[$property] ?? null;
                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
                }
                if ($value !== null && $value !== '') {
                    $value = (string) $value;
                    $row[$field] = $valueMap[$field][$value] ?? $value;
                }
            }
            $names = array_filter(array_map(fn ($id) => $groups[(int) $id] ?? null, (array) ($member['parents'] ?? [])));
            if ($names !== []) {
                $row['groups'] = implode(', ', $names);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Property names of the members (for the field assignment in the wizard).
     *
     * @return list<string>
     */
    public function properties(string $baseUrl, string $apiKey): array
    {
        $base = $this->base($baseUrl);
        $ids = $this->ids($base, $apiKey, 5);
        $names = [];
        foreach ($this->objects($base, $apiKey, 'member', $ids) as $member) {
            $names = array_merge($names, array_keys((array) ($member['properties'] ?? [])));
        }

        return array_values(array_unique($names));
    }

    /** @return list<int|string> */
    private function ids(string $base, string $key, int $limit): array
    {
        $ids = array_values(array_filter((array) ($this->get($base, $key, 'member')['objects'] ?? []), fn ($id) => is_int($id) || is_string($id)));

        return array_slice($ids, 0, $limit);
    }

    private function base(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('The Webling address must start with https://');
        }

        return str_ends_with($url, '/api/1') ? $url : $url.'/api/1';
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<array<string, mixed>>
     */
    private function objects(string $base, string $key, string $type, array $ids): array
    {
        $result = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $data = $this->get($base, $key, $type.'/'.implode(',', $chunk));
            $list = array_is_list($data) ? $data : [$data];
            foreach ($list as $object) {
                if (is_array($object)) {
                    $result[] = $object;
                }
            }
        }

        return $result;
    }

    /** @return array<mixed> */
    private function get(string $base, string $key, string $path): array
    {
        $response = Http::timeout(30)->withHeaders(['apikey' => $key, 'Accept' => 'application/json'])->get($base.'/'.$path);
        if (! $response->successful()) {
            throw new RuntimeException('Webling answered '.$response->status().' for '.$path);
        }

        return (array) $response->json();
    }
}

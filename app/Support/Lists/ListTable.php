<?php

namespace App\Support\Lists;

/**
 * A list ready for screen, XLSX, CSV or PDF. Marked cells changed since the last event (SF15).
 */
class ListTable
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  array<string, true>  $marks  "row:column"
     */
    public function __construct(
        public readonly string $title,
        public readonly array $headers,
        public readonly array $rows,
        public readonly bool $confidential = false,
        public readonly array $marks = [],
    ) {}

    public function marked(int $row, int $column): bool
    {
        return isset($this->marks[$row.':'.$column]);
    }
}

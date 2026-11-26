<?php

namespace App\Support\Lists;

use App\Support\Install\Requirements;
use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Writes a list as XLSX, CSV or PDF (AD13). Text stays text: spreadsheet formulas from entered data are never executed.
 */
class ListExport
{
    public const FORMATS = ['pdf', 'xlsx', 'csv'];

    public const TYPES = ['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=UTF-8'];

    public function render(ListTable $list, string $format, ?string $subtitle = null): string
    {
        if ($format === 'pdf') {
            Requirements::moreMemory();
        }

        return match ($format) {
            'pdf' => Pdf::loadView('pdf.list', ['list' => $list, 'subtitle' => $subtitle])->setPaper('a4', count($list->headers) > 5 ? 'landscape' : 'portrait')->output(),
            'xlsx' => $this->xlsx($list),
            'csv' => $this->csv($list),
            default => throw new RuntimeException('Unknown format '.$format),
        };
    }

    public static function fileName(string $name, string $format): string
    {
        return trim((string) preg_replace('/[^\pL\pN_.-]+/u', '-', $name), '-').'.'.$format;
    }

    private function xlsx(ListTable $list): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kasmin-list');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            foreach ([$list->headers, ...$list->rows] as $row) {
                // StringCell: a text starting with "=" must not become a formula.
                $writer->addRow(new Row(array_map(fn (string $value) => new StringCell($value, null), $row)));
            }
            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function csv(ListTable $list): string
    {
        $handle = fopen('php://temp', 'r+') ?: throw new RuntimeException('No temporary file');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 mark, so spreadsheet programs show umlauts correctly
        foreach ([$list->headers, ...$list->rows] as $row) {
            fputcsv($handle, array_map(self::safe(...), $row), ';', '"', '');
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /** Prevents formulas in CSV; phone numbers such as "+43 1 …" stay as they are. */
    private static function safe(string $value): string
    {
        if (preg_match('/^[=@\t\r]/', $value) === 1 || (preg_match('/^[+-]/', $value) === 1 && preg_match('/^[+-][\d\s().\/,-]*$/', $value) !== 1)) {
            return "'".$value;
        }

        return $value;
    }
}

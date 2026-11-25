<?php

namespace App\Support\Finance;

use App\Console\Commands\ImportPeopleCommand;
use App\Models\ArchivedInvoice;
use App\Models\ContactPoint;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Import of invoices from the system used before (MI07): a table (CSV or XLSX) with one invoice per row and,
 * optionally, a ZIP with the PDFs named like the invoice numbers. Rows that exist already (same number) are
 * updated, so an import can be repeated. Afterwards the own numbering continues after the highest number.
 */
class InvoiceArchive
{
    /** Column names that are understood (first row of the table), in English and German. */
    public const COLUMNS = [
        'number' => ['number', 'nummer', 'rechnungsnummer', 'invoice', 'rechnung', 'nr'],
        'date' => ['date', 'datum', 'rechnungsdatum', 'issued'],
        'name' => ['name', 'recipient', 'empfänger', 'empfaenger', 'payer'],
        'email' => ['email', 'e-mail', 'mail'],
        'amount' => ['amount', 'betrag', 'total', 'summe'],
        'status' => ['status', 'state'],
        'paid' => ['paid', 'paid_on', 'bezahlt', 'bezahlt am', 'zahlungsdatum'],
        'note' => ['note', 'notiz', 'titel', 'title', 'bemerkung'],
    ];

    /**
     * @return array{imported: int, updated: int, pdfs: int, skipped: list<string>, next_number: int|null}
     */
    public function import(string $tablePath, ?string $tableName = null, ?string $zipPath = null): array
    {
        $result = ['imported' => 0, 'updated' => 0, 'pdfs' => 0, 'skipped' => [], 'next_number' => null];
        foreach (ImportPeopleCommand::readFile($tablePath, $tableName) as $i => $raw) {
            $row = $this->row($raw);
            if ($row['number'] === '') {
                $result['skipped'][] = (string) __('finance.archive.no_number', ['row' => $i + 2]);

                continue;
            }
            $values = [
                'issued_on' => $this->date($row['date']), 'payer_name' => $row['name'] !== '' ? mb_substr($row['name'], 0, 200) : null,
                'total_cents' => $this->cents($row['amount']), 'status' => $this->status($row['status'], $row['paid']), 'paid_on' => $this->date($row['paid']),
                'note' => $row['note'] !== '' ? mb_substr($row['note'], 0, 250) : null,
                'person_id' => $row['email'] !== '' ? ContactPoint::query()->where('type', 'email')->where('value', mb_strtolower($row['email']))->value('person_id') : null,
            ];
            $existing = ArchivedInvoice::query()->where('number', $row['number'])->first();
            $existing !== null ? $existing->update($values) : ArchivedInvoice::query()->create(['number' => mb_substr($row['number'], 0, 60)] + $values);
            $result[$existing !== null ? 'updated' : 'imported']++;
        }
        if ($zipPath !== null) {
            $result['pdfs'] = $this->pdfs($zipPath, $result['skipped']);
        }
        $result['next_number'] = $this->continueNumbering();

        return $result;
    }

    /** The own numbering continues after the highest imported number (only ever upwards). */
    public function continueNumbering(): ?int
    {
        $highest = 0;
        foreach (ArchivedInvoice::query()->pluck('number') as $number) {
            if (preg_match_all('/\d+/', (string) $number, $m) > 0) {
                $last = (int) end($m[0]); // "2025-0142" and "R142" both count as 142
                $highest = max($highest, $last < 100000000 ? $last : 0);
            }
        }
        if ($highest === 0 || Settings::bool('invoice_number_per_year') || $highest < Settings::int('invoice_next_number')) {
            return null;
        }
        Settings::set('invoice_next_number', (string) ($highest + 1));

        return $highest + 1;
    }

    /**
     * @param  list<string>  $skipped
     */
    private function pdfs(string $zipPath, array &$skipped): int
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $skipped[] = (string) __('finance.archive.bad_zip');

            return 0;
        }
        $byKey = ArchivedInvoice::query()->get()->keyBy(fn (ArchivedInvoice $a) => $this->key($a->number));
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_ends_with(strtolower($name), '.pdf')) {
                continue;
            }
            $invoice = $byKey->get($this->key(pathinfo($name, PATHINFO_FILENAME)));
            $data = $zip->getFromIndex($i);
            if ($invoice === null || $data === false || ! str_starts_with($data, '%PDF') || strlen($data) > 20 * 1024 * 1024) {
                $skipped[] = (string) __('finance.archive.no_match', ['file' => basename($name)]);

                continue;
            }
            $path = ArchivedInvoice::DIRECTORY.'/'.Str::uuid().'.pdf';
            Storage::disk('local')->put($path, $data);
            if ($invoice->pdf_path !== null) {
                Storage::disk('local')->delete($invoice->pdf_path);
            }
            $invoice->update(['pdf_path' => $path]);
            $count++;
        }
        $zip->close();

        return $count;
    }

    /** Numbers and file names are compared without spaces, case and signs. */
    private function key(string $value): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $value));
    }

    /**
     * @param  array<string, string>  $raw
     * @return array<string, string>
     */
    private function row(array $raw): array
    {
        $row = [];
        foreach (self::COLUMNS as $field => $names) {
            $row[$field] = '';
            foreach ($names as $name) {
                if (isset($raw[$name]) && trim((string) $raw[$name]) !== '') {
                    $row[$field] = trim((string) $raw[$name]);
                    break;
                }
            }
        }

        return $row;
    }

    private function date(string $value): ?string
    {
        if ($value === '' || in_array(strtolower($value), ['yes', 'ja', 'no', 'nein', 'x'], true)) {
            return null;
        }
        try {
            return (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1 ? Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1]) : Carbon::parse($value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** "1.234,50", "1,234.50", "250" and "250,00 €" are understood. */
    private function cents(string $value): int
    {
        $value = (string) preg_replace('/[^0-9,.\-]/', '', $value);
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.') ? str_replace(['.', ','], ['', '.'], $value) : str_replace(',', '', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }

        return (int) round(((float) $value) * 100);
    }

    private function status(string $status, string $paid): string
    {
        $status = strtolower($status);

        return match (true) {
            in_array($status, ['cancelled', 'canceled', 'storniert', 'storno'], true) => 'cancelled',
            in_array($status, ['open', 'offen', 'unpaid', 'unbezahlt'], true) => 'open',
            in_array($status, ['paid', 'bezahlt'], true), $paid !== '' && ! in_array(strtolower($paid), ['no', 'nein'], true) => 'paid',
            $status === '' && $paid === '' => 'open',
            default => 'paid',
        };
    }
}

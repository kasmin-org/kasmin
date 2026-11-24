<?php

namespace App\Console\Commands;

use App\Support\Import\PeopleImporter;
use App\Support\Import\WeblingConnector;
use Illuminate\Console\Command;
use OpenSpout\Reader\CSV\Options;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Import of people from a CSV/XLSX file (first row: field names, see docs) or from Webling. Dry run by default.
 */
class ImportPeopleCommand extends Command
{
    protected $signature = 'kasmin:import:people
        {file? : CSV or XLSX file whose first row contains the Kasmin field names}
        {--source= : name of the source system (required for files), e.g. old-list}
        {--webling= : Webling address, e.g. https://example.webling.eu (API key from KASMIN_WEBLING_KEY)}
        {--mapping= : JSON file with the field assignment for Webling (Kasmin field => Webling property)}
        {--locale=de : language of new people}
        {--import : really import (otherwise dry run)}';

    protected $description = 'Imports people and families (dry run unless --import is given)';

    public function handle(PeopleImporter $importer, WeblingConnector $webling): int
    {
        if ($this->option('webling')) {
            $key = (string) config('kasmin.webling_key', '');
            if ($key === '') {
                $this->error('Set the Webling API key in the environment variable KASMIN_WEBLING_KEY.');

                return self::FAILURE;
            }
            $mapping = $this->option('mapping') ? (array) json_decode((string) file_get_contents((string) $this->option('mapping')), true) : [];
            $rows = $webling->rows((string) $this->option('webling'), $key, $mapping);
            $source = 'webling';
        } else {
            $file = (string) $this->argument('file');
            $source = (string) $this->option('source');
            if ($file === '' || $source === '' || ! is_file($file)) {
                $this->error('Give a file and --source, or --webling.');

                return self::FAILURE;
            }
            $rows = self::readFile($file);
        }
        $locale = (string) $this->option('locale');
        if (! $this->option('import')) {
            $plan = $importer->plan($rows, $source, $locale);
            $this->table(['row', 'action', 'name', 'messages'], array_map(fn ($r) => [$r['row'], $r['action'], $r['name'], implode('; ', $r['messages'])], $plan['rows']));
            $this->info('Dry run: '.json_encode($plan['summary']).', families: '.count($plan['families']).'. Add --import to import.');

            return self::SUCCESS;
        }
        $batch = $importer->import($rows, $source, $locale);
        $this->info('Imported as batch '.$batch->id.': '.json_encode($batch->stats));

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    public static function readFile(string $path, ?string $name = null): array
    {
        $isXlsx = str_ends_with(strtolower($name ?? $path), '.xlsx');
        if ($isXlsx) {
            $reader = new XlsxReader;
        } else {
            $head = (string) file_get_contents($path, false, null, 0, 2000);
            $options = new Options;
            $options->FIELD_DELIMITER = substr_count($head, ';') > substr_count($head, ',') ? ';' : ',';
            $reader = new CsvReader($options);
        }
        $reader->open($path);
        $rows = [];
        $header = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_scalar($v) ? trim((string) $v) : ''), $row->toArray());
                if ($header === null) {
                    $header = array_map(fn ($h) => strtolower(preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h), $values);

                    continue;
                }
                if (array_filter($values) === []) {
                    continue;
                }
                $rows[] = array_combine(array_slice($header, 0, count($values)), array_slice(array_pad($values, count($header), ''), 0, count($header)));
            }
            break;
        }
        $reader->close();

        return array_values(array_filter($rows));
    }
}

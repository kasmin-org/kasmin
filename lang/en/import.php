<?php

return [
    'title' => 'Import people', 'batch' => 'Import', 'batches' => 'Imports',
    'step_source' => '1. Source', 'step_mapping' => '2. Assign the fields',
    'mapping_hint' => 'For each Kasmin field choose the column (or Webling field) it comes from. Leave empty what you do not import. Health data are not imported here.',
    'source_type' => 'Source', 'type_file' => 'CSV or Excel file', 'locale' => 'Language of new people',
    'source' => 'Name of the source', 'source_hint' => 'Lowercase, e.g. old-list. Importing the same source again updates instead of duplicating (needs a source_id column).',
    'file' => 'File (first row: column names)', 'webling_url' => 'Webling address', 'webling_key' => 'Webling API key',
    'webling_key_hint' => 'An API key with read permission. It is used only for this import and not stored.',
    'read' => 'Read source', 'preview' => 'Dry run', 'import' => 'Import', 'need_file' => 'Choose a file and a source name.',
    'read_failed' => 'The source could not be read', 'read_done' => ':count records read – check the field assignment, then start the dry run.',
    'confirm' => 'Import now? :create new, :update updated. The import can be rolled back as long as nothing is changed afterwards.',
    'imported' => 'Import :batch: :created new, :updated updated, :skipped skipped.',
    'result' => 'Result', 'summary' => ':create new, :update updated, :error with errors (skipped).',
    'action' => 'Action', 'name' => 'Name', 'messages' => 'Notes', 'actions' => ['create' => 'new', 'update' => 'update', 'error' => 'error'],
    'families' => 'Families', 'families_hint' => 'High: same e-mail, phone or legal guardians – imported as one family. Medium: only the same address – check by hand (add a "family" column to merge).',
    'confidence' => ['high' => 'high', 'medium' => 'medium'], 'rows' => 'rows', 'no_families' => 'No families found.',
    'time' => 'Time', 'by' => 'By', 'by_api' => 'API', 'status' => 'Status', 'statuses' => ['imported' => 'Imported', 'rolled_back' => 'Rolled back'],
    'stats' => ':created new, :updated updated, :skipped skipped', 'rollback' => 'Roll back',
    'rollback_hint' => 'Deletes what this import created and restores what it changed. Not possible if records were changed afterwards.',
    'rolled_back' => 'Import rolled back.',
];

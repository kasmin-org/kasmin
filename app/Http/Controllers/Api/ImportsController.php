<?php

namespace App\Http\Controllers\Api;

use App\Models\ImportBatch;
use App\Support\Import\FamilyBuilder;
use App\Support\Import\PeopleImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Import of people and families (MI02–MI06, MI10): dry run, family suggestions, import batch, rollback.
 * Scope: import:write. Recommended order: preview → show to a human → import → rollback if needed.
 */
class ImportsController extends ApiController
{
    /** @return array{source: string, rows: list<array<string, mixed>>} */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_.-]+$/'],
            'rows' => ['required', 'array', 'min:1', 'max:5000'], 'rows.*' => ['array'],
        ]);

        return ['source' => $data['source'], 'rows' => array_values($data['rows'])];
    }

    /** Dry run: per row create/update/error with messages, plus family suggestions. Changes nothing. */
    public function preview(Request $request): JsonResponse
    {
        $this->scope('import:write');
        $in = $this->input($request);

        return response()->json(['data' => app(PeopleImporter::class)->plan($in['rows'], $in['source'])]);
    }

    /** Family suggestions only (MI04). */
    public function families(Request $request): JsonResponse
    {
        $this->scope('import:write');

        return response()->json(['data' => app(FamilyBuilder::class)->suggest($this->input($request)['rows'])]);
    }

    /** Import as one batch; repeated imports with the same source and source_id update instead of duplicating. */
    public function store(Request $request): JsonResponse
    {
        $this->scope('import:write');
        $in = $this->input($request);
        $batch = app(PeopleImporter::class)->import($in['rows'], $in['source']);

        return response()->json(['data' => ['batch' => $batch->id, 'stats' => $batch->stats]], 201);
    }

    public function index(): JsonResponse
    {
        $this->scope('import:write');

        return response()->json(['data' => ImportBatch::query()->orderByDesc('id')->limit(100)->get(['id', 'source', 'kind', 'status', 'stats', 'created_at', 'rolled_back_at'])]);
    }

    /** Roll a batch back (only if nothing was changed afterwards). */
    public function rollback(int $batch): JsonResponse
    {
        $this->scope('import:write');
        try {
            app(PeopleImporter::class)->rollback(ImportBatch::query()->findOrFail($batch));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => ['batch' => $batch, 'status' => 'rolled_back']]);
    }
}

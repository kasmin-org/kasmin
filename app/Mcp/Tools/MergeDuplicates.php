<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('merge_duplicates')]
#[Description('Moves everything (contact data, families, guardianships, registrations, invoices, forms) from the person "duplicate" to the person "keep" and deletes the duplicate. This cannot be undone. Refused when the merge would give somebody more rights or both have an invoiced registration for the same event. Show the human both people and ask first.')]
#[IsDestructive]
class MergeDuplicates extends ApiTool
{
    public const ENDPOINT = ['POST', '/duplicates/merge'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keep' => $schema->string()->description('person id that stays')->required(),
            'duplicate' => $schema->string()->description('person id that is merged into the other and deleted')->required(),
            'fields' => $schema->object()->description('whose value stays per field (first_name, last_name, birth_date, gender, locale): "keep" (default) or "duplicate", e.g. {"birth_date": "duplicate"}'),
        ];
    }
}

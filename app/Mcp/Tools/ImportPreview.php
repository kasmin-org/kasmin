<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('import_preview')]
#[Description('Dry run of an import of people and families: per row create/update/error, plus family suggestions. Changes nothing. Show the result to a human before importing.')]
#[IsReadOnly]
class ImportPreview extends ApiTool
{
    public const ENDPOINT = ['POST', '/imports/people/preview'];

    protected string $scope = 'import:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/imports/people/preview';

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'source' => $schema->string()->description('name of the source system, lowercase, e.g. webling or old-list')->required(),
            'rows' => $schema->array()->items($schema->object())->description('rows with fields first_name, last_name, birth_date, email, phone, street, postal_code, city, source_id, groups, membership_type, guardian1_first_name, guardian1_last_name, guardian1_email, guardian1_phone, guardian2_…, participations')->required(),
        ];
    }
}

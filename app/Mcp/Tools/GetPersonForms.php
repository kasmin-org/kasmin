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

#[Name('get_person_forms')]
#[Description('Status of the forms of a person (missing, expired, confirmed), no values.')]
#[IsReadOnly]
class GetPersonForms extends ApiTool
{
    public const ENDPOINT = ['GET', '/people/{person}/forms'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/people/'.rawurlencode((string) ($args['id'] ?? '')).'/forms';
        unset($args['id']);

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('person id')->required(),
        ];
    }
}

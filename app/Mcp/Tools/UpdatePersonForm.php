<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_person_form')]
#[Description('Saves a new version of a form of a person with the given values; values that are not sent stay. The legal guardians have to confirm the new version in the portal. Confidential fields (health and safety data) need the permission health:write and are refused via MCP unless the organisation allows AI processing of all data.')]
class UpdatePersonForm extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/people/{person}/forms/{form}'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['person' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('person id')->required(),
            'form' => $schema->string()->description('key of the form, e.g. safety_sheet')->required(),
            'values' => $schema->object()->description('field key => value, with the keys of list_forms')->required(),
        ];
    }
}

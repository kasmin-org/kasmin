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

#[Name('get_person_form')]
#[Description('The values and versions of one form of a person (e.g. the safety sheet). Answers to confidential questions (health and safety data) are only part of the answer with the permission health:read, and via MCP only when the organisation allows AI processing of all data; otherwise their fields are named in "hidden". Every read of confidential values is recorded in the audit log.')]
#[IsReadOnly]
class GetPersonForm extends ApiTool
{
    public const ENDPOINT = ['GET', '/people/{person}/forms/{form}'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['person' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('person id')->required(),
            'form' => $schema->string()->description('id or exact name of the form, see list_forms')->required(),
        ];
    }
}

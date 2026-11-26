<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_membership')]
#[Description('Gives a person a membership of a membership type from a date (default today). A person has one running membership per type at most. A type with a fee leads to membership invoices when the automation for them runs.')]
class AddMembership extends ApiTool
{
    public const ENDPOINT = ['POST', '/people/{person}/memberships'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('person id')->required(),
            'type' => $schema->string()->description('key of the membership type, see list_membership_types')->required(),
            'starts_on' => $schema->string()->description('first day YYYY-MM-DD, default today'),
            'ends_on' => $schema->string()->description('last day YYYY-MM-DD'),
        ];
    }
}

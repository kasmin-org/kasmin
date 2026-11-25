<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('dismiss_duplicate')]
#[Description('Marks two people as different people; the pair is not proposed as a duplicate again. No data is changed.')]
class DismissDuplicate extends ApiTool
{
    public const ENDPOINT = ['POST', '/duplicates/dismiss'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'first' => $schema->string()->description('person id')->required(),
            'second' => $schema->string()->description('person id of the other person')->required(),
        ];
    }
}

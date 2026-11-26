<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_household')]
#[Description('Creates a family (household) without members; add them with add_household_member.')]
class CreateHousehold extends ApiTool
{
    public const ENDPOINT = ['POST', '/households'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('family name')->required(),
            'street' => $schema->string()->description('street and number'),
            'postal_code' => $schema->string()->description('postal code'),
            'city' => $schema->string()->description('city'),
            'country' => $schema->string()->description('country, two letters, e.g. AT'),
        ];
    }
}

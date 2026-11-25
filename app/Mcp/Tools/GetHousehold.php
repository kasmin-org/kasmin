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

#[Name('get_household')]
#[Description('One family (household) with address, language, invoice recipient and members.')]
#[IsReadOnly]
class GetHousehold extends ApiTool
{
    public const ENDPOINT = ['GET', '/households/{household}'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['household' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('family id')->required(),
        ];
    }
}

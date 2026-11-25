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

#[Name('propose_division')]
#[Description('Places everybody who has no unit yet into the units of a division. With restart true, everybody is taken out first and placed again: placements made by hand are lost.')]
#[IsDestructive]
class ProposeDivision extends ApiTool
{
    public const ENDPOINT = ['POST', '/divisions/{division}/propose'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['division' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('division id')->required(),
            'restart' => $schema->boolean()->description('take everybody out first and place all again'),
        ];
    }
}

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

#[Name('get_statistics')]
#[Description('Statistics of one event (default: the next event with participants): age distribution, age groups for activities and participants per year; with finance:read also costs and discounts. Counts only, no names.')]
#[IsReadOnly]
class GetStatistics extends ApiTool
{
    public const ENDPOINT = ['GET', '/statistics'];

    protected string $scope = 'events:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'event' => $schema->string()->description('event id'),
            'role' => $schema->string()->description('child (default), junior_counsellor, staff, guest or all'),
            'groups' => $schema->integer()->description('number of age groups, default 3'),
        ];
    }
}

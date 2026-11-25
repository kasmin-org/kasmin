<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_event_series')]
#[Description('Changes the name or the type of an event series.')]
class UpdateEventSeries extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/event-series/{series}'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['series' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('series id')->required(),
            'name' => $schema->string()->description('name'),
            'type' => $schema->string()->description('camp, alumni, meeting or other'),
        ];
    }
}

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

#[Name('list_events')]
#[Description('Lists events (camps and other events), newest first.')]
#[IsReadOnly]
class ListEvents extends ApiTool
{
    protected string $scope = 'events:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/events';

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('draft, announced, open, closed, running, finished'),
        ];
    }
}

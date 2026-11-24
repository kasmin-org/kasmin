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

#[Name('list_participants')]
#[Description('Participants of an event with status and waitlist position.')]
#[IsReadOnly]
class ListParticipants extends ApiTool
{
    protected string $scope = 'events:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/events/'.rawurlencode((string) ($args['id'] ?? '')).'/participations';
        unset($args['id']);

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('event id')->required(),
            'status' => $schema->string()->description('registered, waitlist, offered, cancelled …'),
            'role' => $schema->string()->description('child, junior_counsellor, staff, guest'),
        ];
    }
}

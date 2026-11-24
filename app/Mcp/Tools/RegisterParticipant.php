<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('register_participant')]
#[Description('Registers a person for an event in a role. Age limits and capacity apply; if the event is full the person goes on the waitlist. Sends the registration confirmation to the legal guardians.')]
class RegisterParticipant extends ApiTool
{
    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/events/'.rawurlencode((string) ($args['id'] ?? '')).'/registrations';
        unset($args['id']);

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('event id')->required(),
            'person' => $schema->string()->description('person id')->required(),
            'role' => $schema->string()->description('role, e.g. child')->required(),
        ];
    }
}

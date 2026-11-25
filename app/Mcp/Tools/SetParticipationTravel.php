<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_participation_travel')]
#[Description('Sets how a participant travels to an event that has a bus: bus or car. Everybody travels by bus unless set to car: adults who drive, or a child that its legal guardians bring themselves.')]
class SetParticipationTravel extends ApiTool
{
    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/participations/'.rawurlencode((string) ($args['id'] ?? ''));
        unset($args['id']);

        return $this->call('PATCH', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('participation id from list_participants')->required(),
            'travel' => $schema->string()->description('bus or car')->required(),
        ];
    }
}

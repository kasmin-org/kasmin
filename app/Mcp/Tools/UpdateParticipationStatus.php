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

#[Name('update_participation_status')]
#[Description('Changes the status of a participation. The status cancelled frees the place for the waitlist (which can send offers to other families), sends the cancellation confirmation to the family and recalculates draft invoices; a reason can be stored. A participation that is cancelled, declined, attended or a no-show cannot be cancelled. Other statuses are set as they are, without mails and without the checks of a registration. Ask the human first.')]
#[IsDestructive]
class UpdateParticipationStatus extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/participations/{participation}'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['participation' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('participation id from list_participants')->required(),
            'status' => $schema->string()->description('requested, registered, confirmed, waitlist, offered, cancelled, declined, attended or no_show')->required(),
            'reason' => $schema->string()->description('reason, only with the status cancelled'),
        ];
    }
}

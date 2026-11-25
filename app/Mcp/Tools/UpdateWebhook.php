<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_webhook')]
#[Description('Changes the name or the events of a webhook, or switches it on or off. The address and the signing secret cannot be changed via MCP, and webhooks are created in the admin interface: an assistant must not send data of the organisation to a new address. Ask the human first.')]
class UpdateWebhook extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/webhooks/{webhook}'];

    protected string $scope = 'settings:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['webhook' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('webhook id')->required(),
            'name' => $schema->string()->description('name'),
            'events' => $schema->array()->items($schema->string())->description('events the webhook is called for, e.g. participation.created, invoice.paid'),
            'active' => $schema->boolean()->description('switched on or off'),
        ];
    }
}

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

#[Name('delete_webhook')]
#[Description('Deletes a webhook for good, with its delivery log; the address is no longer called. Ask the human first.')]
#[IsDestructive]
class DeleteWebhook extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/webhooks/{webhook}'];

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
        ];
    }
}

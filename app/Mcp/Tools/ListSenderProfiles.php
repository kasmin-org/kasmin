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

#[Name('list_sender_profiles')]
#[Description('Sender profiles: from name and address, reply-to and the name of the mail account they send with. Passwords and keys of mail accounts are never part of an answer.')]
#[IsReadOnly]
class ListSenderProfiles extends ApiTool
{
    public const ENDPOINT = ['GET', '/sender-profiles'];

    protected string $scope = 'mail:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }
}

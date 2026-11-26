<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('clear_bad_address')]
#[Description('Marks an e-mail address that came back as working again: mails are sent to it as before. Only when a human has checked the address.')]
class ClearBadAddress extends ApiTool
{
    public const ENDPOINT = ['POST', '/bad-addresses/{address}/clear'];

    protected string $scope = 'mail:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['address' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('id from list_bad_addresses')->required(),
        ];
    }
}

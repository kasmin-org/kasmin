<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_fund')]
#[Description('Creates a fund, optionally assigned to a bank or cash account. Needs "Use funds" in the global settings.')]
class CreateFund extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/funds'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('unique name')->required(),
            'account' => $schema->string()->description('bank or cash account the money lies on: id or exact name'),
            'note' => $schema->string()->description('note'),
            'sort' => $schema->integer()->description('order'),
        ];
    }
}

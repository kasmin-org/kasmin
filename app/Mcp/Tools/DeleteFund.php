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

#[Name('delete_fund')]
#[Description('Deletes a fund that holds nothing and that no cost centre uses; never the general fund. Ask the human first.')]
#[IsDestructive]
class DeleteFund extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/accounting/funds/{fund}'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fund' => $schema->string()->description('the fund: id or exact name')->required(),
        ];
    }
}

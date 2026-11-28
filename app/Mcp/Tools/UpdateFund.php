<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_fund')]
#[Description('Changes name, bank or cash account, note or order of a fund; only the fields that are sent change.')]
class UpdateFund extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/accounting/funds/{fund}'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->argsWithClear($request, ['account', 'note']));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fund' => $schema->string()->description('the fund: id or exact name')->required(),
            'name' => $schema->string()->description('name'),
            'account' => $schema->string()->description('bank or cash account: id or exact name'),
            'note' => $schema->string()->description('note'),
            'sort' => $schema->integer()->description('order'),
            'clear' => $schema->array()->items($schema->string())->description('optional fields to empty: account, note'),
        ];
    }
}

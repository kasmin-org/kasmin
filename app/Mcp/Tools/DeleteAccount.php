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

#[Name('delete_account')]
#[Description('Deletes an own account without bookings. System accounts and accounts with bookings cannot be deleted; switch them off with update_account (active false) instead. Ask the human first.')]
#[IsDestructive]
class DeleteAccount extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/accounting/accounts/{account}'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()->description('the account: id or exact name or account number')->required(),
        ];
    }
}

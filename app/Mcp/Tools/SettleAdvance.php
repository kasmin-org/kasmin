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

#[Name('settle_advance')]
#[Description('Settles an advance once its receipts are decided: returned must be exactly what is still open (0 if the receipts cover it); receipts above the advance become an approved expense claim.')]
#[IsDestructive]
class SettleAdvance extends ApiTool
{
    public const ENDPOINT = ['POST', '/advances/{advance}/settle'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['advance' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('advance id from list_advances')->required(),
            'returned' => $schema->number()->description('amount in euro the person gave back')->required(),
            'account' => $schema->string()->description('id of the bank or cash account it went into, or "bank" or "cash"'),
            'date' => $schema->string()->description('day of the settlement YYYY-MM-DD')->required(),
        ];
    }
}

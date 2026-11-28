<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('transfer_between_funds')]
#[Description('Moves money from one fund to another by a decision of the board: a booking of the kind fund_transfer with the reason as text. The answer is the booking.')]
class TransferBetweenFunds extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/funds/{fund}/transfers'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fund' => $schema->string()->description('the fund the money comes from: id or exact name')->required(),
            'to' => $schema->string()->description('the fund that gets the money: id or exact name')->required(),
            'amount' => $schema->number()->description('amount in the currency of the installation')->required(),
            'text' => $schema->string()->description('reason, e.g. the decision of the board')->required(),
            'date' => $schema->string()->description('date (YYYY-MM-DD), default today'),
        ];
    }
}

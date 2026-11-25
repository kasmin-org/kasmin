<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('assign_payment')]
#[Description('Assigns a payment that is not assigned yet (match suggested or unmatched) to an open invoice; without invoice the suggested invoice is confirmed. More than the open amount becomes a credit of the family with a refund proposal; a fully paid invoice sends the payment confirmation to the family. Ask the human first.')]
class AssignPayment extends ApiTool
{
    public const ENDPOINT = ['POST', '/payments/{payment}/assign'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['payment' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('payment id from list_payments')->required(),
            'invoice' => $schema->integer()->description('invoice id; leave out to confirm the suggested invoice'),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('send_invoice')]
#[Description('Sends an issued invoice (again) to the payer by mail, with the PDF. Not for drafts. Ask the human first.')]
class SendInvoice extends ApiTool
{
    public const ENDPOINT = ['POST', '/invoices/{invoice}/send'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['invoice' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('invoice id')->required(),
        ];
    }
}

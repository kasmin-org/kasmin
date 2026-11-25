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

#[Name('issue_invoice')]
#[Description('Issues a draft invoice: it gets its number, reference and due date for good and can then only be cancelled with a credit note. Unless send is false, the invoice is then sent to the payer by mail with the PDF. Only drafts can be issued. Show the human the invoice and ask first.')]
#[IsDestructive]
class IssueInvoice extends ApiTool
{
    public const ENDPOINT = ['POST', '/invoices/{invoice}/issue'];

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
            'send' => $schema->boolean()->description('send the invoice by mail after issuing (default true)'),
        ];
    }
}

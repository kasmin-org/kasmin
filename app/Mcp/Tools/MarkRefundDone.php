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

#[Name('mark_refund_done')]
#[Description('Records that the bank transfer of a proposed refund was made on a day: the credit of the family is used up. No money is moved by this tool; only record what a human has transferred.')]
#[IsDestructive]
class MarkRefundDone extends ApiTool
{
    public const ENDPOINT = ['POST', '/refunds/{refund}/done'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['refund' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('refund id from list_refunds')->required(),
            'date' => $schema->string()->description('day of the transfer YYYY-MM-DD, not in the future')->required(),
        ];
    }
}

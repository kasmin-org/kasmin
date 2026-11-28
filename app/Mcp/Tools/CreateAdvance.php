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

#[Name('create_advance')]
#[Description('Records an advance handed to a person from a bank or cash account (booked at once). Only record what a human has handed out.')]
#[IsDestructive]
class CreateAdvance extends ApiTool
{
    public const ENDPOINT = ['POST', '/advances'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('public id of the person')->required(),
            'amount' => $schema->number()->description('amount in euro')->required(),
            'account' => $schema->string()->description('id of a bank or cash account, or "bank" or "cash"')->required(),
            'date' => $schema->string()->description('day it was handed out YYYY-MM-DD')->required(),
            'purpose' => $schema->string()->description('what it is for'),
        ];
    }
}

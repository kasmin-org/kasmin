<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_deletion_requests')]
#[Description('People who asked for their data to be deleted and are not anonymised yet, each with what would be deleted and kept and why anonymising is refused now.')]
#[IsReadOnly]
class ListDeletionRequests extends ApiTool
{
    public const ENDPOINT = ['GET', '/deletion-requests'];

    protected string $scope = 'audit:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }
}

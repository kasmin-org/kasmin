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

#[Name('reject_public_submission')]
#[Description('Declines a pending application from a public form, with an optional note; its answers are deleted for good. Ask the human first.')]
#[IsDestructive]
class RejectPublicSubmission extends ApiTool
{
    public const ENDPOINT = ['POST', '/public-submissions/{submission}/reject'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['submission' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('submission id')->required(),
            'note' => $schema->string()->description('note, stored with the submission'),
        ];
    }
}

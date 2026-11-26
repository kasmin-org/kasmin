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

#[Name('list_mail_template_versions')]
#[Description('The versions of a mail template, newest first, each with its subject and text and who changed it.')]
#[IsReadOnly]
class ListMailTemplateVersions extends ApiTool
{
    public const ENDPOINT = ['GET', '/mail-templates/{template}/versions'];

    protected string $scope = 'mail:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['template' => 'key']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('template key')->required(),
        ];
    }
}

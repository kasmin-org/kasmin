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

#[Name('update_mail_template')]
#[Description('Changes the contents of a mail template; every change is a new version (see restore_mail_template_version). The template is used for all mails of its kind from then on: check the result with preview_mail_template, show it to the human and ask first.')]
#[IsDestructive]
class UpdateMailTemplate extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/mail-templates/{template}'];

    protected string $scope = 'mail:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['template' => 'key']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('template key')->required(),
            'contents' => $schema->array()->items($schema->object())->description('contents per language: objects with locale (de or en), subject, html and optionally text; a language that is not sent stays')->required(),
        ];
    }
}

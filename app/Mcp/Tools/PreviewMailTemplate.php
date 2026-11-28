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

#[Name('preview_mail_template')]
#[Description('Renders a template, or new contents for it, with example data without saving.')]
#[IsReadOnly]
class PreviewMailTemplate extends ApiTool
{
    public const ENDPOINT = ['POST', '/mail-templates/{template}/preview'];

    protected string $scope = 'mail:read';

    public function handle(Request $request): Response
    {
        $args = $this->withRecord($this->args($request), 'template');
        $path = '/mail-templates/'.rawurlencode((string) ($args['template'] ?? '')).'/preview';
        unset($args['template']);

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'template' => $schema->string()->description('id or exact name of the mail template, see list_mail_templates')->required(),
            'subject' => $schema->string()->description('new subject'),
            'html' => $schema->string()->description('new html'),
        ];
    }
}

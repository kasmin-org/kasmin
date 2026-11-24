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
    protected string $scope = 'mail:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/mail-templates/'.rawurlencode((string) ($args['key'] ?? '')).'/preview';
        unset($args['key']);

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('template key')->required(),
            'locale' => $schema->string()->description('de or en'),
            'subject' => $schema->string()->description('new subject'),
            'html' => $schema->string()->description('new html'),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('restore_mail_template_version')]
#[Description('Makes the contents of an earlier version of a mail template the current ones. This is a new version, nothing is lost; the template is used for all mails of its kind from then on. Ask the human first.')]
class RestoreMailTemplateVersion extends ApiTool
{
    public const ENDPOINT = ['POST', '/mail-templates/{template}/versions/{version}/restore'];

    protected string $scope = 'mail:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->withRecord($this->args($request), 'template'));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'template' => $schema->string()->description('id or exact name of the mail template, see list_mail_templates')->required(),
            'version' => $schema->integer()->description('number of the version from list_mail_template_versions')->required(),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('save_form_definition')]
#[Description('Creates a form or replaces its definition (new version, answers stay). Fields that are missing in the definition are removed.')]
class SaveFormDefinition extends ApiTool
{
    protected string $scope = 'settings:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/forms/'.rawurlencode((string) ($args['form'] ?? '')).'/definition';

        return $this->call('PUT', $path, (array) ($args['definition'] ?? []));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'form' => $schema->string()->description('key of the form, e.g. safety_sheet')->required(),
            'definition' => $schema->object()->description('definition as returned by get_form_definition')->required(),
        ];
    }
}

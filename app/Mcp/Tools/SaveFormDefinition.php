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
#[Description('Creates a form or replaces its definition (new version, answers stay). Fields are matched by their key; a field without a key is new, and fields that are missing in the definition are removed. Keep the keys of get_form_definition.')]
class SaveFormDefinition extends ApiTool
{
    public const ENDPOINT = ['PUT', '/forms/{form}/definition'];

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
            'form' => $schema->string()->description('id or exact name of the form; a new form is created when no form has this name')->required(),
            'definition' => $schema->object()->description('definition as returned by get_form_definition')->required(),
        ];
    }
}

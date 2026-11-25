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

#[Name('get_form_definition')]
#[Description('Definition of a form with all fields, choices and rules (no answers). Edit it and save it with save_form_definition.')]
#[IsReadOnly]
class GetFormDefinition extends ApiTool
{
    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/forms/'.rawurlencode((string) ($args['form'] ?? '')).'/definition';
        unset($args['form']);

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'form' => $schema->string()->description('key of the form, e.g. safety_sheet')->required(),
        ];
    }
}

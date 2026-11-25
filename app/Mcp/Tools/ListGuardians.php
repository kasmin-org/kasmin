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

#[Name('list_guardians')]
#[Description('The legal guardians of a person (ended guardianships are left out).')]
#[IsReadOnly]
class ListGuardians extends ApiTool
{
    public const ENDPOINT = ['GET', '/people/{person}/guardians'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/people/'.rawurlencode((string) ($args['person'] ?? '')).'/guardians';
        unset($args['person']);

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('person id')->required(),
        ];
    }
}

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

#[Name('end_guardianship')]
#[Description('Ends the guardianship of a legal guardian for a person today. The legal guardian stays a person and a member of its families.')]
#[IsDestructive]
class EndGuardianship extends ApiTool
{
    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/people/'.rawurlencode((string) ($args['person'] ?? '')).'/guardians/'.rawurlencode((string) ($args['guardian'] ?? ''));
        unset($args['person'], $args['guardian']);

        return $this->call('DELETE', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('person id of the child')->required(),
            'guardian' => $schema->string()->description('person id of the legal guardian')->required(),
        ];
    }
}

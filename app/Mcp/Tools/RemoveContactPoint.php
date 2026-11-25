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

#[Name('remove_contact_point')]
#[Description('Removes an e-mail address or a phone number of a person. The address the person logs in with cannot be removed.')]
#[IsDestructive]
class RemoveContactPoint extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/people/{person}/contact-points/{point}'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/people/'.rawurlencode((string) ($args['person'] ?? '')).'/contact-points/'.rawurlencode((string) ($args['id'] ?? ''));
        unset($args['person'], $args['id']);

        return $this->call('DELETE', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('person id')->required(),
            'id' => $schema->integer()->description('id of the address or number from list_contact_points')->required(),
        ];
    }
}

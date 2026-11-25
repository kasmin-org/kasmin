<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_contact_point')]
#[Description('Adds an e-mail address or a phone number to a person. The first one of its type becomes the primary one. The login of the person is not changed.')]
class AddContactPoint extends ApiTool
{
    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/people/'.rawurlencode((string) ($args['person'] ?? '')).'/contact-points';
        unset($args['person']);

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('person id')->required(),
            'type' => $schema->string()->description('email or phone')->required(),
            'value' => $schema->string()->description('the address or number')->required(),
            'purposes' => $schema->array()->items($schema->string())->description('what it is used for, login, information, invoices, emergency'),
            'is_primary' => $schema->boolean()->description('true: replaces the primary one of its type'),
        ];
    }
}

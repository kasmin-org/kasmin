<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_person')]
#[Description('Creates a person (optionally a child with up to two legal guardians, who get their own records and logins). Recorded as an import batch that can be rolled back.')]
class CreatePerson extends ApiTool
{
    public const ENDPOINT = ['POST', '/people'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/people';

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'first_name' => $schema->string()->description('first name')->required(),
            'last_name' => $schema->string()->description('last name')->required(),
            'birth_date' => $schema->string()->description('date of birth YYYY-MM-DD'),
            'email' => $schema->string()->description('e-mail of the person (adults)'),
            'phone' => $schema->string()->description('phone'),
            'guardian1_first_name' => $schema->string()->description('first legal guardian, first name'),
            'guardian1_last_name' => $schema->string()->description('first legal guardian, last name'),
            'guardian1_email' => $schema->string()->description('first legal guardian, e-mail'),
            'guardian1_phone' => $schema->string()->description('first legal guardian, phone'),
            'street' => $schema->string()->description('street'),
            'postal_code' => $schema->string()->description('postcode'),
            'city' => $schema->string()->description('city'),
        ];
    }
}

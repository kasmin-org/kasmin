<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_person')]
#[Description('Changes the name, date of birth, gender or language of a person; only the fields that are sent change. Contact data, legal guardians, families and groups have their own tools. A changed date of birth has effect on age limits, guardianships and prices.')]
class UpdatePerson extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/people/{person}'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->argsWithClear($request, ['birth_date', 'gender']), ['person' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('person id')->required(),
            'first_name' => $schema->string()->description('first name'),
            'last_name' => $schema->string()->description('last name'),
            'birth_date' => $schema->string()->description('date of birth YYYY-MM-DD'),
            'gender' => $schema->string()->description('gender'),
            'clear' => $schema->array()->items($schema->string())->description('names of fields to empty: birth_date, gender'),
        ];
    }
}

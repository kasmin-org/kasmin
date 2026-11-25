<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_household')]
#[Description('Changes name, address, language or invoice recipient of a family; only the fields that are sent change. The invoice recipient gets the invoices of the family and must be one of its legal guardians; cleared, the recipient is chosen automatically.')]
class UpdateHousehold extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/households/{household}'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->argsWithClear($request, ['street', 'postal_code', 'city', 'invoice_recipient']), ['household' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('family id')->required(),
            'name' => $schema->string()->description('family name'),
            'locale' => $schema->string()->description('language of the family: de or en'),
            'street' => $schema->string()->description('street and number'),
            'postal_code' => $schema->string()->description('postal code'),
            'city' => $schema->string()->description('city'),
            'country' => $schema->string()->description('country, two letters, e.g. AT'),
            'invoice_recipient' => $schema->string()->description('person id of a legal guardian of the family'),
            'clear' => $schema->array()->items($schema->string())->description('names of fields to empty: street, postal_code, city, invoice_recipient'),
        ];
    }
}

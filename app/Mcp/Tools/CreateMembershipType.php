<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_membership_type')]
#[Description('Creates a membership type. The key cannot be changed later.')]
class CreateMembershipType extends ApiTool
{
    public const ENDPOINT = ['POST', '/membership-types'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/membership-types';

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('short key: letters, digits, dashes, underscores')->required(),
            'name' => $schema->object()->description('names: {"de": "…", "en": "…"}, both languages')->required(),
            'pays_fee' => $schema->boolean()->description('whether members of this type pay the yearly fee'),
            'fee' => $schema->number()->description('yearly fee in the invoice currency'),
        ];
    }
}

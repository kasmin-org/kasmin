<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_membership_type')]
#[Description('Changes the names, whether the type pays a fee, or the yearly fee of a membership type.')]
class UpdateMembershipType extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/membership-types/{type}'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/membership-types/'.rawurlencode((string) ($args['key'] ?? ''));
        unset($args['key']);

        return $this->call('PATCH', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('key of the membership type')->required(),
            'name' => $schema->string()->description('name'),
            'pays_fee' => $schema->boolean()->description('whether members of this type pay the yearly fee'),
            'fee' => $schema->number()->description('yearly fee in the invoice currency'),
        ];
    }
}

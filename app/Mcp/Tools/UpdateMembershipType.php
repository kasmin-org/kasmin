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
#[Description('Changes the name, whether the type pays a fee, or the yearly fee of a membership type.')]
class UpdateMembershipType extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/membership-types/{type}'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        $args = $this->withRecord($this->args($request), 'type');
        $path = '/membership-types/'.rawurlencode((string) ($args['type'] ?? ''));
        unset($args['type']);

        return $this->call('PATCH', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('id or exact name of the membership type, see list_membership_types')->required(),
            'name' => $schema->string()->description('new name, unique among the membership types'),
            'pays_fee' => $schema->boolean()->description('whether members of this type pay the yearly fee'),
            'fee' => $schema->number()->description('yearly fee in the invoice currency'),
        ];
    }
}

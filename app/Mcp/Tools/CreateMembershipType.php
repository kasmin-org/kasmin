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
#[Description('Creates a membership type. Its name must be unique; the answer has its id.')]
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
            'name' => $schema->string()->description('name, unique among the membership types')->required(),
            'pays_fee' => $schema->boolean()->description('whether members of this type pay the yearly fee'),
            'fee' => $schema->number()->description('yearly fee in the invoice currency'),
            'revenue_account' => $schema->string()->description('income account the fees are booked on: id or exact name (empty: membership fees)'),
            'cost_center' => $schema->string()->description('cost centre the fees are booked on, also as an instalment of an event: id or exact name (empty: the one of the event)'),
        ];
    }
}

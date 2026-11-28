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

#[Name('delete_cost_center')]
#[Description('Deletes a cost centre. Its bookings move to move_to, or - only typed-in bookings in open periods - are deleted for good with delete_bookings true and confirm_count, the number of bookings from list_cost_centers. Not possible for the standard one, while it receives an allocation in an open period or with bookings in closed periods. Show the human what happens and ask first.')]
#[IsDestructive]
class DeleteCostCenter extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/accounting/cost-centers/{center}'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['center' => 'cost_center']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'cost_center' => $schema->string()->description('the cost centre: id or exact name')->required(),
            'move_to' => $schema->string()->description('cost centre that gets the bookings: id or exact name'),
            'delete_bookings' => $schema->boolean()->description('delete the bookings for good instead (only typed-in bookings)'),
            'confirm_count' => $schema->integer()->description('with delete_bookings: the number of bookings that will be deleted, as confirmation'),
        ];
    }
}

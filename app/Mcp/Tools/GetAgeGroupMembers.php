<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_age_group_members')]
#[Description('The members of the age groups for activities of one event (see get_statistics for the groups themselves): one row per person with the chosen fields. Holds names and dates of birth, so it needs people:read in addition; every call is logged. Nothing confidential.')]
#[IsReadOnly]
class GetAgeGroupMembers extends ApiTool
{
    public const ENDPOINT = ['GET', '/statistics/age-groups'];

    protected string $scope = 'events:read,people:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'event' => $schema->string()->description('event id; default: the next event with participants'),
            'role' => $schema->string()->description('child (default), junior_counsellor, staff, guest or all'),
            'groups' => $schema->integer()->description('number of age groups, 2 to 8, default 3'),
            'fields' => $schema->array()->items($schema->string())->description('fields per person: group, first_name, last_name, age, birth_date, gender, role; default all'),
        ];
    }
}

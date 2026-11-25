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

#[Name('get_dashboard')]
#[Description('The figures of the dashboard: the next event of every series with registrations, free places, waitlist and bus; to-dos; safety sheets confirmed and missing per upcoming event (counts only). With finance:read also open and overdue invoices (amounts in cents), with automations:read the runs that are due and failed.')]
#[IsReadOnly]
class GetDashboard extends ApiTool
{
    public const ENDPOINT = ['GET', '/dashboard'];

    protected string $scope = 'events:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }
}

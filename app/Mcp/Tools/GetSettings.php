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

#[Name('get_settings')]
#[Description('The settings that can be changed via the API: organisation (name, address, e-mail, website, legal footer), invoices (due days, reminders), legal guardians per family, approval of mailings, digest time. Bank data, public address, mail test mode, embedding and the AI settings are not available.')]
#[IsReadOnly]
class GetSettings extends ApiTool
{
    public const ENDPOINT = ['GET', '/settings'];

    protected string $scope = 'settings:write';

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

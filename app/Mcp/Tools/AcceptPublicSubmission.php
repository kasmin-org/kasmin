<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('accept_public_submission')]
#[Description('Approves a pending application from a public form: it is carried out as the form says (the person is created, added to the group or registered for the event), and the mails of that step are sent. Ask the human first.')]
class AcceptPublicSubmission extends ApiTool
{
    public const ENDPOINT = ['POST', '/public-submissions/{submission}/accept'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['submission' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('submission id')->required(),
        ];
    }
}

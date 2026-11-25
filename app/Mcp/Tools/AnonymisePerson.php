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

#[Name('anonymise_person')]
#[Description('Anonymises a person: name, contact data, forms and other personal data are deleted for good, only what the law requires (e.g. invoices) is kept. This cannot be undone. Always call it with preview true first (nothing is changed, the answer shows what would be deleted and kept and why it would be refused), show the result to the human, and anonymise only after their explicit approval, with the reason. Refused while the person still has a role.')]
#[IsDestructive]
class AnonymisePerson extends ApiTool
{
    public const ENDPOINT = ['POST', '/people/{person}/anonymise'];

    protected string $scope = 'privacy:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['person' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('person id')->required(),
            'preview' => $schema->boolean()->description('true: change nothing, only show what would be deleted and kept'),
            'reason' => $schema->string()->description('reason, recorded; required unless preview is true'),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('prepare_mailing')]
#[Description('Renders all mails of a draft for review. Nothing is sent.')]
class PrepareMailing extends ApiTool
{
    protected string $scope = 'mail:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/mailings/'.rawurlencode((string) ($args['id'] ?? '')).'/prepare';
        unset($args['id']);

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('mailing id')->required(),
        ];
    }
}

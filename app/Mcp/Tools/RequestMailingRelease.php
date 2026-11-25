<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('request_mailing_release')]
#[Description('Asks humans for the approval to send a prepared mailing.')]
class RequestMailingRelease extends ApiTool
{
    public const ENDPOINT = ['POST', '/mailings/{mailing}/release'];

    protected string $scope = 'mail:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/mailings/'.rawurlencode((string) ($args['id'] ?? '')).'/release';
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
